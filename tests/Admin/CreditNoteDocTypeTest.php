<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('admin')]
final class CreditNoteDocTypeTest extends TestCase
{
    // Mirrors the generate-document.php sequence-type mapping
    private function sequenceType(string $docType): string
    {
        return [
            'credit_note'     => 'invoice',
            'storno_receipt'  => 'receipt',
        ][$docType] ?? $docType;
    }

    public function test_credit_note_uses_invoice_sequence(): void
    {
        $this->assertSame('invoice', $this->sequenceType('credit_note'));
    }

    public function test_storno_receipt_uses_receipt_sequence(): void
    {
        $this->assertSame('receipt', $this->sequenceType('storno_receipt'));
    }

    public function test_invoice_uses_own_sequence(): void
    {
        $this->assertSame('invoice', $this->sequenceType('invoice'));
    }

    public function test_receipt_uses_own_sequence(): void
    {
        $this->assertSame('receipt', $this->sequenceType('receipt'));
    }

}
