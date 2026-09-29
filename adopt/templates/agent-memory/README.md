# Agent memory

Standing guidance for the laravel-factory control loops. Edit these files
directly; loops never take instructions from PR comments.

- `security-loop.md`: context for the security loop's Claude fallback.
  Accepted-risk advisories go in `composer.json` `config.audit.ignore`
  (advisory ID + reason), where `composer audit` honours them natively.
- `mutation-skip.txt`: classes the mutation loop should skip (one FQCN per line).
- `prod-error-skip.txt`: error-group IDs the prod-error loop should skip.
