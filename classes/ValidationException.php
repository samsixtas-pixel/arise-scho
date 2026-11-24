<?php
/**
 * ValidationException.php
 * Carries field-level validation errors back to the form.
 */

class ValidationException extends RuntimeException
{
    /** @var string[] */
    private array $errors;

    public function __construct(array $errors, string $message = 'Please correct the highlighted fields.')
    {
        parent::__construct($message);
        $this->errors = array_values($errors);
    }

    /** @return string[] */
    public function errors(): array
    {
        return $this->errors;
    }
}
