<?php

namespace App\Services;

class GtfsValidationResult
{
    public array  $errors    = [];
    public array  $warnings  = [];
    public bool   $valid     = false;
    public string $checked_at;

    public function __construct()
    {
        $this->checked_at = now()->toIso8601String();
    }

    public function addError(string $rule, string $message, ?string $entityId = null): void
    {
        $this->errors[] = array_filter([
            'rule'      => $rule,
            'message'   => $message,
            'entity_id' => $entityId,
        ]);
    }

    public function addWarning(string $rule, string $message, ?string $entityId = null): void
    {
        $this->warnings[] = array_filter([
            'rule'      => $rule,
            'message'   => $message,
            'entity_id' => $entityId,
        ]);
    }

    public function finalize(): self
    {
        $this->valid = empty($this->errors);
        return $this;
    }

    public function toArray(): array
    {
        return [
            'valid'      => $this->valid,
            'errors'     => $this->errors,
            'warnings'   => $this->warnings,
            'checked_at' => $this->checked_at,
        ];
    }
}
