<?php

class DomainValidationException extends RuntimeException
{
}

class AcademicSequenceException extends DomainValidationException
{
    public function __construct(public array $validation)
    {
        parent::__construct(implode(' ', array_column($validation['conflicts'], 'message')));
    }
}

class DomainConflictException extends RuntimeException
{
}

class DomainNotFoundException extends RuntimeException
{
}
