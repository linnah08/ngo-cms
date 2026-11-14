---
paths:
  - "includes/documents/**"
  - "includes/document_numbers.php"
  - "admin/generate-document.php"
  - "admin/download-document.php"
---

# PDF documents (invoices, receipts, donation certificates)

- mPDF via Composer. Generators in `includes/documents/`: base `DocumentGenerator.php`, then `InvoiceGenerator`, `ReceiptGenerator`, `DonationCertGenerator`.
- `admin/generate-document.php` (POST) creates the PDF and its DB record; `admin/download-document.php` serves it with auth.
- Files live in `documents/{invoices|receipts|donation_certs}/{year}/` — `.htaccess`-protected, not in git.
- Numbers come from the `document_sequences` table, always through `document_sequence_next()` (`includes/document_numbers.php`), which also handles numbering shared with another site's DB via `DOC_SEQUENCE_DB`. Records go in `documents`; B2B/donor data in `orders.invoice_data` (JSON).
