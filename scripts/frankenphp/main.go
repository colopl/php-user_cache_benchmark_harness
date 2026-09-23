// A single-process driver: one HTTP worker, sequential paired measurements.
// JSONL records retain request wall time separately from the PHP cache batch.
package main

import (
	"encoding/json"
	"flag"
	"fmt"
	"io"
	"math/rand"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"path/filepath"
	"runtime"
	"strconv"
	"time"

	fp "github.com/dunglas/frankenphp"
)

type benchCase struct {
	Payload     string
	Keys, Loops int
	Temperature string
	Mutate      bool
	TTL         int
}

func main() {
	root := flag.String("root", "", "PHP fixture directory")
	mode := flag.String("mode", "worker", "classic or worker")
	pairs := flag.Int("pairs", 15, "paired samples per case")
	session := flag.Int("session", 1, "independent session number")
	seed := flag.Int64("seed", 1701, "fixed shuffle seed")
	only := flag.String("case", "", "optional payload filter")
	aa := flag.Bool("aa", false, "reference/reference noise run")
	quick := flag.Bool("quick", false, "correctness smoke; not performance evidence")
	flag.Parse()
	if *root == "" || (*mode != "classic" && *mode != "worker") || *pairs < 1 || *session < 1 || flag.NArg() != 0 {
		panic("require -root, positive -pairs/-session, and -mode classic|worker")
	}
	if *only != "" {
		valid := false
		for _, payload := range []string{"null", "bool", "int", "double", "string32", "string300", "string4k", "string64k", "packed8", "packed512", "hash32", "nested"} {
			valid = valid || *only == payload
		}
		if !valid {
			panic("unknown -case payload")
		}
	}
	if os.Getenv("UC_LIFECYCLE_OPT_IN") == "" {
		if err := os.Setenv("UC_LIFECYCLE_OPT_IN", "1"); err != nil {
			panic(err)
		}
	} else if os.Getenv("UC_LIFECYCLE_OPT_IN") != "1" {
		panic("benchmark requires UC_LIFECYCLE_OPT_IN=1")
	}
	server, err := fp.NewServer(*root)
	if err != nil {
		panic(err)
	}
	options := []fp.Option{fp.WithServer(server), fp.WithNumThreads(2), fp.WithMaxThreads(2),
		fp.WithPhpIni(map[string]string{"user_cache.enable": "1", "user_cache.shm_size": "256M", "user_cache.entries_hint": "65536", "display_errors": "1", "memory_limit": "768M", "max_execution_time": "0", "opcache.enable": "0", "opcache.jit": "0"})}
	if *mode == "worker" {
		options = append(options, fp.WithWorkers("bench", filepath.Join(*root, "worker.php"), 1, fp.WithWorkerServerScope(server)))
	}
	if err := fp.Init(options...); err != nil {
		panic(err)
	}
	defer fp.Shutdown()
	host := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if err := server.ServeHTTP(w, r); err != nil {
			http.Error(w, err.Error(), 500)
		}
	}))
	defer host.Close()
	client := &http.Client{Timeout: 90 * time.Second}
	enc := json.NewEncoder(os.Stdout)
	writeJSON := func(value any) {
		if err := enc.Encode(value); err != nil {
			panic(err)
		}
	}
	writeJSON(map[string]any{"type": "metadata", "session": *session, "seed": *seed, "pairs": *pairs, "aa": *aa, "quick": *quick, "mode": *mode, "go": runtime.Version(), "frankenphp": "51e6246e71f335b96ac22d7782f5b20392555109", "reference": "synthetic cache using official persistent_zval helpers"})
	cases := []benchCase{}
	for _, payload := range []string{"null", "bool", "int", "double", "string32", "string300", "string4k", "string64k", "packed8", "packed512", "hash32", "nested"} {
		cases = append(cases, benchCase{payload, 1, 1000000, "warm", false, 0})
		if payload != "null" && payload != "bool" && payload != "int" && payload != "double" {
			cases = append(cases, benchCase{payload, 1, 1000000, "warm", true, 0})
		}
	}
	for _, payload := range []string{"int", "string300", "packed8", "hash32"} {
		cases = append(cases, benchCase{payload, 32768, 32768, "cold", false, 0}, benchCase{payload, 32768, 1048576, "warm", false, 0}, benchCase{payload, 32768, 1048576, "warm", true, 0})
	}
	cases = append(cases, benchCase{"packed8", 1, 1000000, "warm", false, 3600})
	rng := rand.New(rand.NewSource(*seed))
	for caseID, c := range cases {
		if *only != "" && c.Payload != *only {
			continue
		}
		if *quick {
			c.Loops = 100
		}
		for pair := 0; pair < *pairs; pair++ {
			backends := []string{"user_cache", "reference"}
			if *aa {
				backends = []string{"reference", "reference"}
			}
			if rng.Intn(2) == 1 {
				backends[0], backends[1] = backends[1], backends[0]
			}
			if pair == 0 && !*aa {
				backends = append(backends, "raw")
			}
			for order, backend := range backends {
				query := url.Values{"backend": {backend}, "payload": {c.Payload}, "keys": {strconv.Itoa(c.Keys)}, "loops": {strconv.Itoa(c.Loops)}, "temperature": {c.Temperature}, "ttl": {strconv.Itoa(c.TTL)}}
				if c.Mutate {
					query.Set("mutate", "1")
				}
				start := time.Now()
				resp, err := client.Get(host.URL + "/" + *mode + ".php?" + query.Encode())
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
				var result map[string]any
				if err = json.Unmarshal(body, &result); err != nil {
					panic(fmt.Sprintf("%s: %s: %v", backend, body, err))
				}
				if result["backend"] != backend || result["expected_checksum"] == nil ||
					result["checksum"] != result["expected_checksum"] || result["mutation_isolated"] != true {
					panic(fmt.Sprintf("invalid benchmark result: %s", body))
				}
				result["http_status"] = resp.StatusCode
				result["http_elapsed_ns"] = time.Since(start).Nanoseconds()
				result["type"] = "sample"
				result["case_id"] = caseID
				result["pair"] = pair
				result["order"] = order
				result["session"] = *session
				writeJSON(result)
			}
		}
	}
}
