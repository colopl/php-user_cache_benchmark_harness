// Closed-loop concurrent HTTP benchmark. Setup/warmup are outside timing.
// Each invocation owns a fresh host; run backend/worker variants sequentially.
package main

import (
	"encoding/json"
	"flag"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"sync"
	"sync/atomic"
	"time"

	fp "github.com/dunglas/frankenphp"
)

type response struct {
	HTTPStatus      int   `json:"http_status"`
	Thread          int64 `json:"thread"`
	BatchNS         int64 `json:"batch_ns"`
	CPUNS           int64 `json:"thread_cpu_ns"`
	Reads           int   `json:"reads"`
	Writes          int   `json:"writes"`
	Failures        int   `json:"failures"`
	Checksum        int   `json:"checksum"`
	Ready           bool  `json:"ready"`
	Pins            int   `json:"pins"`
	SharedUsed      int   `json:"shared_used"`
	PersistentBytes int   `json:"reference_persistent_bytes"`
	PHPHeap         int   `json:"php_heap"`
}

type sample struct {
	Type      string `json:"type"`
	Sequence  uint64 `json:"sequence"`
	LatencyNS int64  `json:"http_latency_ns"`
	response
}

func main() {
	root := flag.String("root", "", "PHP fixture directory")
	backend := flag.String("backend", "user_cache", "user_cache or reference")
	kind := flag.String("kind", "array", "array (TTL 3600) or scalar (TTL 0)")
	workers := flag.Int("workers", 1, "PHP worker count")
	clients := flag.Int("clients", 0, "closed-loop clients (default twice worker count)")
	operations := flag.Int("operations", 100, "cache operations per HTTP: 1 or 100")
	mixed := flag.Bool("mixed", false, "99% reads / 1% overwrite, otherwise 100% reads")
	duration := flag.Duration("duration", 5*time.Second, "offered closed-loop measurement window")
	flag.Parse()
	if *root == "" || *workers < 1 || *clients < 0 || *duration <= 0 || flag.NArg() != 0 ||
		(*kind != "array" && *kind != "scalar") ||
		(*backend != "user_cache" && *backend != "reference") || (*operations != 1 && *operations != 100) {
		panic("invalid arguments")
	}
	if os.Getenv("UC_LIFECYCLE_OPT_IN") == "" {
		if err := os.Setenv("UC_LIFECYCLE_OPT_IN", "1"); err != nil {
			panic(err)
		}
	} else if os.Getenv("UC_LIFECYCLE_OPT_IN") != "1" {
		panic("benchmark requires UC_LIFECYCLE_OPT_IN=1")
	}
	script, ttl := "scale.php", 3600
	if *kind == "scalar" {
		script, ttl = "scale-scalar.php", 0
	}
	if *clients == 0 {
		*clients = 2 * *workers
	}
	server, err := fp.NewServer(*root)
	if err != nil {
		panic(err)
	}
	if err = fp.Init(fp.WithServer(server), fp.WithNumThreads(*workers+1), fp.WithMaxThreads(*workers+1),
		fp.WithPhpIni(map[string]string{"user_cache.enable": "1", "user_cache.shm_size": "32M", "display_errors": "1", "memory_limit": "256M", "max_execution_time": "0", "opcache.enable": "0", "opcache.jit": "0"}),
		fp.WithWorkers("scale", filepath.Join(*root, script), *workers, fp.WithWorkerServerScope(server))); err != nil {
		panic(err)
	}
	defer fp.Shutdown()
	host := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if err := server.ServeHTTP(w, r); err != nil {
			http.Error(w, err.Error(), 500)
		}
	}))
	defer host.Close()
	transport := http.DefaultTransport.(*http.Transport).Clone()
	transport.MaxIdleConnsPerHost = *clients
	client := &http.Client{Timeout: 30 * time.Second, Transport: transport}
	request := func(action string, sequence uint64) sample {
		query := url.Values{"backend": {*backend}, "action": {action}, "sequence": {strconv.FormatUint(sequence, 10)}, "operations": {strconv.Itoa(*operations)}}
		if *mixed {
			query.Set("mixed", "1")
		}
		start := time.Now()
		resp, err := client.Get(host.URL + "/" + script + "?" + query.Encode())
		if err != nil {
			panic(err)
		}
		body, err := io.ReadAll(resp.Body)
		resp.Body.Close()
		if err != nil {
			panic(err)
		}
		if resp.StatusCode != http.StatusOK {
			panic(fmt.Sprintf("HTTP %d: %s", resp.StatusCode, body))
		}
		result := sample{Type: "sample", Sequence: sequence, LatencyNS: time.Since(start).Nanoseconds()}
		if err = json.Unmarshal(body, &result.response); err != nil {
			panic(fmt.Sprintf("%s: %s", err, body))
		}
		result.HTTPStatus = resp.StatusCode
		return result
	}
	if !request("setup", 0).Ready {
		panic("setup failed")
	}
	seen := map[int64]bool{}
	for attempt := 0; attempt < 20 && len(seen) < *workers; attempt++ {
		var wg sync.WaitGroup
		ch := make(chan sample, *clients)
		for i := 0; i < *clients; i++ {
			wg.Add(1)
			go func() { defer wg.Done(); ch <- request("warm", 0) }()
		}
		wg.Wait()
		close(ch)
		for result := range ch {
			if !result.Ready {
				panic("warmup failed")
			}
			seen[result.Thread] = true
		}
	}
	if len(seen) != *workers {
		panic("not every PHP worker warmed up")
	}
	var sequence atomic.Uint64
	var wg sync.WaitGroup
	startSignal := make(chan struct{})
	results := make([][]sample, *clients)
	var deadline time.Time
	for i := 0; i < *clients; i++ {
		wg.Add(1)
		go func(index int) {
			defer wg.Done()
			<-startSignal
			for time.Now().Before(deadline) {
				seq := sequence.Add(1) - 1
				results[index] = append(results[index], request("run", seq))
			}
		}(i)
	}
	start := time.Now()
	deadline = start.Add(*duration)
	close(startSignal)
	wg.Wait()
	elapsed := time.Since(start)
	latencies := []int64{}
	var reads, writes, failures, validationErrors, threadCPU, batchNS int64
	for _, local := range results {
		for _, r := range local {
			latencies = append(latencies, r.LatencyNS)
			reads += int64(r.Reads)
			writes += int64(r.Writes)
			failures += int64(r.Failures)
			threadCPU += r.CPUNS
			batchNS += r.BatchNS
			if r.Checksum != r.Reads*307 || r.Reads+r.Writes != *operations || r.Thread == 0 ||
				r.BatchNS <= 0 || r.CPUNS < 0 {
				validationErrors++
			}
		}
	}
	if len(latencies) == 0 {
		panic("no measured requests")
	}
	sort.Slice(latencies, func(i, j int) bool { return latencies[i] < latencies[j] })
	percentile := func(p int) int64 { return latencies[(len(latencies)-1)*p/100] }
	status := request("status", 0)
	enc := json.NewEncoder(os.Stdout)
	writeJSON := func(value any) {
		if err := enc.Encode(value); err != nil {
			panic(err)
		}
	}
	writeJSON(map[string]any{"type": "metadata", "backend": *backend, "kind": *kind, "fixture_kind": *kind, "workers": *workers, "clients": *clients, "operations_per_http": *operations, "mixed": *mixed, "closed_loop": true, "key_count": 32, "ttl": ttl, "frankenphp": "51e6246e71f335b96ac22d7782f5b20392555109", "reference": "synthetic cache using official persistent_zval helpers"})
	writeJSON(map[string]any{"type": "summary", "elapsed_ns": elapsed.Nanoseconds(), "offered_window_ns": duration.Nanoseconds(), "requests": len(latencies), "reads": reads, "writes": writes, "failures": failures, "validation_errors": validationErrors, "http_errors": 0,
		"requests_per_second": float64(len(latencies)) / elapsed.Seconds(), "operations_per_second": float64(reads+writes) / elapsed.Seconds(), "http_p50_ns": percentile(50), "http_p95_ns": percentile(95), "http_p99_ns": percentile(99), "thread_cpu_ns": threadCPU, "batch_ns": batchNS, "status": status.response})
	for _, local := range results {
		for _, r := range local {
			writeJSON(r)
		}
	}
	if failures != 0 || validationErrors != 0 || status.Pins != 0 {
		os.Exit(1)
	}
}
