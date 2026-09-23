/* Test-only cache wrapper around FrankenPHP's unmodified zval.h.
 * Include in frankenphp.c with UC_BENCH_REFERENCE; never ship in production.
 * Process storage is protected by one rwlock. Each full PHP execution owns
 * its local COW prototypes. HTTP boundaries retain them, like user_cache.
 */
#include <pthread.h>
#include <time.h>

typedef struct {
  zval value;
  uint64_t generation;
  time_t expires;
} uc_bench_entry;

typedef struct {
  zval value;
  uint64_t generation;
} uc_bench_local_entry;

static HashTable uc_bench_entries;
static pthread_rwlock_t uc_bench_lock = PTHREAD_RWLOCK_INITIALIZER;
static uint64_t uc_bench_generation;
static THREAD_LOCAL HashTable *uc_bench_local;
static zval uc_bench_raw;

static void uc_bench_entry_dtor(zval *z) {
  uc_bench_entry *entry = Z_PTR_P(z);
  persistent_zval_free(&entry->value);
  pefree(entry, 1);
}

static void uc_bench_local_dtor(zval *z) {
  uc_bench_local_entry *entry = Z_PTR_P(z);
  zval_ptr_dtor(&entry->value);
  efree(entry);
}

static void uc_bench_drop_local(void) {
  if (uc_bench_local) {
    zend_hash_destroy(uc_bench_local);
    efree(uc_bench_local);
    uc_bench_local = NULL;
  }
}

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_uc_bench_store, 0, 2, _IS_BOOL, 0)
ZEND_ARG_TYPE_INFO(0, key, IS_STRING, 0)
ZEND_ARG_TYPE_INFO(0, value, IS_MIXED, 0)
ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, ttl, IS_LONG, 0, "0")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_uc_bench_fetch, 0, 1, IS_MIXED, 0)
ZEND_ARG_TYPE_INFO(0, key, IS_STRING, 0)
ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, local, _IS_BOOL, 0, "true")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_uc_bench_delete, 0, 1, _IS_BOOL, 0)
ZEND_ARG_TYPE_INFO(0, key, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_uc_bench_void, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_uc_bench_value, 0, 1, IS_VOID, 0)
ZEND_ARG_TYPE_INFO(0, value, IS_MIXED, 0)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_uc_bench_read, 0, 0, IS_MIXED, 0)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_uc_bench_flags, 0, 1, IS_ARRAY, 0)
ZEND_ARG_TYPE_INFO(0, value, IS_MIXED, 0)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_uc_bench_long, 0, 0, IS_LONG, 0)
ZEND_END_ARG_INFO()

static size_t uc_bench_string_bytes(zend_string *str) {
  return ZSTR_IS_INTERNED(str) ? 0 : ZEND_MM_ALIGNED_SIZE(_ZSTR_STRUCT_SIZE(ZSTR_LEN(str)));
}

/* Owned allocation requests, not allocator arena/RSS or usable allocation size.
 * Only diagnostic calls walk storage; no accounting work enters timed fetches. */
static size_t uc_bench_value_bytes(zval *value) {
  if (Z_TYPE_P(value) == IS_STRING) return uc_bench_string_bytes(Z_STR_P(value));
  if (Z_TYPE_P(value) == IS_PTR) {
    persistent_zval_enum_t *e = Z_PTR_P(value);
    return sizeof(*e) + uc_bench_string_bytes(e->class_name) + uc_bench_string_bytes(e->case_name);
  }
  if (Z_TYPE_P(value) != IS_ARRAY || (GC_FLAGS(Z_ARRVAL_P(value)) & IS_ARRAY_IMMUTABLE)) return 0;
  HashTable *ht = Z_ARRVAL_P(value);
  size_t bytes = sizeof(*ht) + (HT_IS_INITIALIZED(ht) ? HT_SIZE(ht) : 0);
  zend_string *key;
  zval *child;
  ZEND_HASH_FOREACH_STR_KEY_VAL(ht, key, child) {
    if (key) bytes += uc_bench_string_bytes(key);
    bytes += uc_bench_value_bytes(child);
  } ZEND_HASH_FOREACH_END();
  return bytes;
}

PHP_FUNCTION(uc_bench_reference_persistent_bytes) {
  ZEND_PARSE_PARAMETERS_NONE();
  pthread_rwlock_rdlock(&uc_bench_lock);
  size_t bytes = HT_IS_INITIALIZED(&uc_bench_entries) ? HT_SIZE(&uc_bench_entries) : 0;
  zend_string *key;
  uc_bench_entry *entry;
  ZEND_HASH_FOREACH_STR_KEY_PTR(&uc_bench_entries, key, entry) {
    bytes += uc_bench_string_bytes(key) + sizeof(*entry) + uc_bench_value_bytes(&entry->value);
  } ZEND_HASH_FOREACH_END();
  if (!Z_ISUNDEF(uc_bench_raw)) bytes += uc_bench_value_bytes(&uc_bench_raw);
  pthread_rwlock_unlock(&uc_bench_lock);
  RETURN_LONG((zend_long) bytes);
}

PHP_FUNCTION(uc_bench_thread_cpu_ns) {
  ZEND_PARSE_PARAMETERS_NONE();
  struct timespec now;
  if (clock_gettime(CLOCK_THREAD_CPUTIME_ID, &now) != 0) {
    zend_throw_error(NULL, "CLOCK_THREAD_CPUTIME_ID failed");
    RETURN_THROWS();
  }
  RETURN_LONG((zend_long) now.tv_sec * 1000000000 + now.tv_nsec);
}

PHP_FUNCTION(uc_bench_thread_id) {
  ZEND_PARSE_PARAMETERS_NONE();
  RETURN_LONG((zend_long) (uintptr_t) pthread_self());
}

PHP_FUNCTION(uc_bench_reference_store) {
  zend_string *key;
  zval *value;
  zend_long ttl = 0;
  ZEND_PARSE_PARAMETERS_START(2, 3)
  Z_PARAM_STR(key)
  Z_PARAM_ZVAL(value)
  Z_PARAM_OPTIONAL
  Z_PARAM_LONG(ttl)
  ZEND_PARSE_PARAMETERS_END();
  if (!persistent_zval_validate(value) || ttl < 0) {
    zend_value_error("Reference cache supports scalars, arrays and enums; ttl must be nonnegative");
    RETURN_THROWS();
  }
  uc_bench_entry *entry = pemalloc(sizeof(*entry), 1);
  persistent_zval_persist(&entry->value, value);
  entry->expires = ttl ? time(NULL) + ttl : 0;
  zend_string *persistent_key = zend_string_init(ZSTR_VAL(key), ZSTR_LEN(key), 1);
  /* Match user_cache publication: discard this worker's obsolete prototype. */
  if (uc_bench_local) zend_hash_del(uc_bench_local, key);
  pthread_rwlock_wrlock(&uc_bench_lock);
  entry->generation = ++uc_bench_generation;
  uint64_t generation = entry->generation;
  zend_hash_update_ptr(&uc_bench_entries, persistent_key, entry);
  pthread_rwlock_unlock(&uc_bench_lock);
  zend_string_release(persistent_key);
  /* user_cache's direct string range is length < 4096; its seed threshold
   * tests payload_size (length + trailing NUL) > 256, hence length >= 256.
   * Preserve the same input COW sharing, rather than copying on first fetch.
   * Shared generation and TTL are still checked before every local hit. */
  if (Z_TYPE_P(value) == IS_STRING && Z_STRLEN_P(value) >= 256 && Z_STRLEN_P(value) < 4096) {
    if (!uc_bench_local) {
      uc_bench_local = emalloc(sizeof(HashTable));
      zend_hash_init(uc_bench_local, 8, NULL, uc_bench_local_dtor, 0);
    }
    uc_bench_local_entry *cached = emalloc(sizeof(*cached));
    cached->generation = generation;
    ZVAL_COPY(&cached->value, value);
    zend_hash_update_ptr(uc_bench_local, key, cached);
  }
  RETURN_TRUE;
}

PHP_FUNCTION(uc_bench_reference_fetch) {
  zend_string *key;
  bool local = true;
  ZEND_PARSE_PARAMETERS_START(1, 2)
  Z_PARAM_STR(key)
  Z_PARAM_OPTIONAL
  Z_PARAM_BOOL(local)
  ZEND_PARSE_PARAMETERS_END();
  pthread_rwlock_rdlock(&uc_bench_lock);
  uc_bench_entry *entry = zend_hash_find_ptr(&uc_bench_entries, key);
  if (!entry || (entry->expires && entry->expires <= time(NULL))) {
    pthread_rwlock_unlock(&uc_bench_lock);
    if (uc_bench_local) zend_hash_del(uc_bench_local, key);
    RETURN_NULL();
  }
  uc_bench_local_entry *cached = local && uc_bench_local
      ? zend_hash_find_ptr(uc_bench_local, key) : NULL;
  if (cached && cached->generation == entry->generation) {
    ZVAL_COPY(return_value, &cached->value);
    pthread_rwlock_unlock(&uc_bench_lock);
    return;
  }
  persistent_zval_to_request(return_value, &entry->value);
  uint64_t generation = entry->generation;
  pthread_rwlock_unlock(&uc_bench_lock);
  if (EG(exception)) RETURN_THROWS();
  if (local) {
    if (!uc_bench_local) {
      uc_bench_local = emalloc(sizeof(HashTable));
      zend_hash_init(uc_bench_local, 8, NULL, uc_bench_local_dtor, 0);
    }
    cached = emalloc(sizeof(*cached));
    cached->generation = generation;
    ZVAL_COPY(&cached->value, return_value);
    zend_hash_update_ptr(uc_bench_local, key, cached);
  }
}

PHP_FUNCTION(uc_bench_reference_delete) {
  zend_string *key;
  ZEND_PARSE_PARAMETERS_START(1, 1)
  Z_PARAM_STR(key)
  ZEND_PARSE_PARAMETERS_END();
  pthread_rwlock_wrlock(&uc_bench_lock);
  bool deleted = zend_hash_del(&uc_bench_entries, key) == SUCCESS;
  pthread_rwlock_unlock(&uc_bench_lock);
  if (uc_bench_local) zend_hash_del(uc_bench_local, key);
  RETURN_BOOL(deleted);
}

PHP_FUNCTION(uc_bench_reference_clear) {
  ZEND_PARSE_PARAMETERS_NONE();
  pthread_rwlock_wrlock(&uc_bench_lock);
  zend_hash_destroy(&uc_bench_entries);
  zend_hash_init(&uc_bench_entries, 8, NULL, uc_bench_entry_dtor, 1);
  if (!Z_ISUNDEF(uc_bench_raw)) {
    persistent_zval_free(&uc_bench_raw);
    ZVAL_UNDEF(&uc_bench_raw);
  }
  pthread_rwlock_unlock(&uc_bench_lock);
  uc_bench_drop_local();
}

PHP_FUNCTION(uc_bench_reference_drop_local) {
  ZEND_PARSE_PARAMETERS_NONE();
  uc_bench_drop_local();
}

PHP_FUNCTION(uc_bench_reference_prepare_raw) {
  zval *value;
  ZEND_PARSE_PARAMETERS_START(1, 1)
  Z_PARAM_ZVAL(value)
  ZEND_PARSE_PARAMETERS_END();
  if (!persistent_zval_validate(value)) {
    zend_value_error("Unsupported raw reference value");
    RETURN_THROWS();
  }
  /* The harness runs raw cases serially with no concurrent publication.
   * This deliberately measures the helper lower bound without cache work. */
  if (!Z_ISUNDEF(uc_bench_raw)) persistent_zval_free(&uc_bench_raw);
  persistent_zval_persist(&uc_bench_raw, value);
}

PHP_FUNCTION(uc_bench_reference_read_raw) {
  ZEND_PARSE_PARAMETERS_NONE();
  if (Z_ISUNDEF(uc_bench_raw)) {
    zend_throw_error(NULL, "Prepare raw reference before reading");
    RETURN_THROWS();
  }
  persistent_zval_to_request(return_value, &uc_bench_raw);
}

PHP_FUNCTION(uc_bench_value_flags) {
  zval *value;
  ZEND_PARSE_PARAMETERS_START(1, 1)
  Z_PARAM_ZVAL(value)
  ZEND_PARSE_PARAMETERS_END();
  array_init(return_value);
  add_assoc_bool(return_value, "interned", Z_TYPE_P(value) == IS_STRING && ZSTR_IS_INTERNED(Z_STR_P(value)));
  add_assoc_bool(return_value, "immutable", Z_TYPE_P(value) == IS_ARRAY && (GC_FLAGS(Z_ARRVAL_P(value)) & IS_ARRAY_IMMUTABLE));
}

static const zend_function_entry uc_bench_functions[] = {
  PHP_FE(uc_bench_reference_store, arginfo_uc_bench_store)
  PHP_FE(uc_bench_reference_fetch, arginfo_uc_bench_fetch)
  PHP_FE(uc_bench_reference_delete, arginfo_uc_bench_delete)
  PHP_FE(uc_bench_reference_clear, arginfo_uc_bench_void)
  PHP_FE(uc_bench_reference_drop_local, arginfo_uc_bench_void)
  PHP_FE(uc_bench_reference_prepare_raw, arginfo_uc_bench_value)
  PHP_FE(uc_bench_reference_read_raw, arginfo_uc_bench_read)
  PHP_FE(uc_bench_value_flags, arginfo_uc_bench_flags)
  PHP_FE(uc_bench_reference_persistent_bytes, arginfo_uc_bench_long)
  PHP_FE(uc_bench_thread_cpu_ns, arginfo_uc_bench_long)
  PHP_FE(uc_bench_thread_id, arginfo_uc_bench_long)
  PHP_FE_END
};

static zend_result uc_bench_minit(void) {
  zend_hash_init(&uc_bench_entries, 8, NULL, uc_bench_entry_dtor, 1);
  ZVAL_UNDEF(&uc_bench_raw);
  return zend_register_functions(NULL, uc_bench_functions, NULL, MODULE_PERSISTENT);
}

PHP_RSHUTDOWN_FUNCTION(uc_bench) {
  uc_bench_drop_local();
  return SUCCESS;
}

PHP_MSHUTDOWN_FUNCTION(uc_bench) {
  zend_hash_destroy(&uc_bench_entries);
  if (!Z_ISUNDEF(uc_bench_raw)) persistent_zval_free(&uc_bench_raw);
  return SUCCESS;
}
