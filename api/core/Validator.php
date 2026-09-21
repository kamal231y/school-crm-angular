<?php
/**
 * Chhota rule-based validator.
 * Rules: required|email|numeric|date|min:3|max:50|in:a,b|phone
 */
class Validator
{
    private array $errors = [];

    /** mbstring har shared host par nahi hoti, isliye fallback. */
    private static function len($value): int
    {
        return function_exists('mb_strlen')
            ? mb_strlen((string) $value)
            : strlen((string) $value);
    }

    public function __construct(private array $data) {}

    public function check(array $rules): self
    {
        foreach ($rules as $field => $ruleStr) {
            $value = $this->data[$field] ?? null;
            foreach (explode('|', $ruleStr) as $rule) {
                [$name, $param] = array_pad(explode(':', $rule, 2), 2, null);
                $this->apply($field, $value, $name, $param);
            }
        }
        return $this;
    }

    private function apply(string $field, $value, string $rule, ?string $param): void
    {
        $label = ucwords(str_replace('_', ' ', $field));
        $empty = $value === null || $value === '';

        if ($rule === 'required' && $empty) {
            $this->errors[$field] = "$label is required";
            return;
        }
        if ($empty) {
            return; // baaki rules sirf non-empty par
        }

        switch ($rule) {
            case 'email':
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) $this->errors[$field] = "$label is not a valid email";
                break;
            case 'phone':
                if (!preg_match('/^[0-9]{10,15}$/', preg_replace('/\D/', '', (string)$value))) $this->errors[$field] = "$label must be a valid mobile number";
                break;
            case 'numeric':
                if (!is_numeric($value)) $this->errors[$field] = "$label must be a number";
                break;
            case 'date':
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$value)) $this->errors[$field] = "$label must be in YYYY-MM-DD format";
                break;
            case 'min':
                if (self::len($value) < (int)$param) $this->errors[$field] = "$label must be at least $param characters";
                break;
            case 'max':
                if (self::len($value) > (int)$param) $this->errors[$field] = "$label may not exceed $param characters";
                break;
            case 'in':
                if (!in_array((string)$value, explode(',', (string)$param), true)) $this->errors[$field] = "$label has an invalid value";
                break;
        }
    }

    public function fails(): bool { return !empty($this->errors); }
    public function errors(): array { return $this->errors; }

    /** Fail hone par turant 422 response bhej deta hai. */
    public function stopOnFail(): void
    {
        if ($this->fails()) {
            Response::error('Please correct the highlighted fields', 422, $this->errors);
        }
    }
}
