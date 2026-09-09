<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Validation\RouteConstraintValidator;

/**
 * Batch 19 coverage: RouteConstraintValidator runtime-evaluation guards —
 * the matcher-closure preg failure path (PREG_BACKTRACK_LIMIT_ERROR with a
 * deliberately lowered pcre.backtrack_limit) and the \Throwable wrapper that
 * converts a ValueError raised for an invalid-UTF-8 subject (/u modifier)
 * into an InvalidConfigurationException. Both triggers are deterministic.
 */
final class Batch19RouteConstraintValidatorRuntimeTest extends TestCase
{
    public function testClosurePregFailureRethrowsConfigurationError(): void
    {
        $validator = new RouteConstraintValidator();
        // The registration ReDoS guard rejects every exponentially-backtracking
        // shape, so a legal (polynomial) pattern must be made to exhaust the
        // backtrack budget instead: lower pcre.backtrack_limit at runtime.
        // '(ab|abc)+' passes the guard (no '+'/'*' inside the group) yet needs
        // to backtrack when the subject does not end in a matchable suffix.
        $validator->addCustom('deep', '/^(ab|abc)+$/');

        $previousLimit = ini_get('pcre.backtrack_limit');
        if ($previousLimit === false) {
            $previousLimit = '1000000';
        }
        $previousReporting = error_reporting(0);
        ini_set('pcre.backtrack_limit', '5');
        try {
            // Each rejected 'abc' alternative at every suffix position costs
            // backtrack steps; 10 'ab' pairs + 'x' needs far more than 5.
            try {
                $validator->test('p', 'deep', str_repeat('ab', 10) . 'x');
                self::fail('expected InvalidConfigurationException from preg failure');
            } catch (InvalidConfigurationException $e) {
                self::assertStringContainsString('failed during evaluation', $e->getMessage());
            }
        } finally {
            ini_set('pcre.backtrack_limit', $previousLimit);
            error_reporting($previousReporting);
        }
    }

    public function testInvalidUtf8SubjectWrappedAsConfigurationError(): void
    {
        $validator = new RouteConstraintValidator();
        $validator->addCustom('lower', '/^[a-z]+$/u');

        // "\xFF" is not valid UTF-8; evaluating a /u pattern against it raises
        // a ValueError, which test() wraps into InvalidConfigurationException.
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('failed during evaluation');
        $validator->test('p', 'lower', "\xFF\xFF");
    }

    public function testValidUtf8CustomConstraintStillMatches(): void
    {
        $validator = new RouteConstraintValidator();
        $validator->addCustom('lower', '/^[a-z]+$/u');

        self::assertTrue($validator->test('p', 'lower', 'abcdef'));
        self::assertFalse($validator->test('p', 'lower', 'ABC'));
    }
}
