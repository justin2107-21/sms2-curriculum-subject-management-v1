<?php

/**
 * Demo rows of the Compliance Validator. Rows whose reference code or rule name starts with DEV-DEMO (the development seed) are hidden by default
 * and never counted by the Dashboard, Export or CIMF. The strings below are constant SQL fragments (no user input).
 */
final class ComplianceDemo
{
    public const PREFIX = 'DEV-DEMO';

    public static function ruleIsDemo(string $rule, string $reference): string
    {
        return "(COALESCE($rule.rule_name, '') LIKE 'DEV-DEMO%' OR COALESCE($reference.reference_code, '') LIKE 'DEV-DEMO%')";
    }

    public static function referenceIsDemo(string $reference): string
    {
        return "COALESCE($reference.reference_code, '') LIKE 'DEV-DEMO%'";
    }

    /** A run that evaluated at least one demo rule. */
    public static function runIsDemo(string $run): string
    {
        return "EXISTS (SELECT 1 FROM compliance_validation_results AS demo_results WHERE demo_results.validation_run_id = $run.id AND demo_results.rule_name_snapshot LIKE 'DEV-DEMO%')";
    }

    public static function isDemoName(?string $value): bool
    {
        return $value !== null && str_starts_with($value, self::PREFIX);
    }
}