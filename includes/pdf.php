<?php
/**
 * includes/pdf.php — FPDF wrapper for Aurora Form Workflow
 *
 * Provides:
 *   generateSubmissionPDF() — builds a PDF for a signed submission and returns
 *                             the absolute path to the temporary file.
 *
 * SETUP REQUIRED:
 *   1. Download FPDF from https://www.fpdf.org  (fpdf183.zip or later)
 *   2. Extract and upload the contents of the zip so that
 *      includes/fpdf/fpdf.php  exists on the server.
 *   3. That's it — no Composer needed.
 *
 * The generated PDF is written to sys_get_temp_dir() and returned as a path.
 * The caller is responsible for deleting the temp file after use.
 */

$fpdfPath = __DIR__ . '/fpdf/fpdf.php';
if (!file_exists($fpdfPath)) {
    // FPDF not yet installed — log and return gracefully.
    error_log('[Aurora PDF] FPDF not found at ' . $fpdfPath . '. Download from https://www.fpdf.org and extract to includes/fpdf/.');
    if (!function_exists('generateSubmissionPDF')) {
        function generateSubmissionPDF(): string { return ''; }
    }
    return;
}

require_once $fpdfPath;

// ─────────────────────────────────────────────────────────────────────────────
// AuroraPDF — thin FPDF subclass for Aurora styling
// ─────────────────────────────────────────────────────────────────────────────

class AuroraPDF extends FPDF
{
    public string $formName  = '';
    public string $stageName = '';

    /** Branded page header */
    public function Header(): void
    {
        // Navy band
        $this->SetFillColor(30, 58, 95); // #1e3a5f
        $this->Rect(0, 0, 210, 22, 'F');

        $this->SetY(6);
        $this->SetFont('Arial', 'B', 13);
        $this->SetTextColor(255, 255, 255);
        $this->Cell(0, 7, 'Aurora Early Education', 0, 1, 'L');

        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(147, 197, 253); // blue-300
        $this->Cell(0, 5, 'Form Workflow — Signed Document', 0, 1, 'L');

        $this->SetTextColor(0, 0, 0);
        $this->SetY(28);
    }

    /** Subtle page number footer */
    public function Footer(): void
    {
        $this->SetY(-12);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(156, 163, 175); // gray-400
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . ' — Aurora Form Workflow', 0, 0, 'C');
    }

    /** Section heading */
    public function SectionHeading(string $title): void
    {
        $this->SetFont('Arial', 'B', 9);
        $this->SetTextColor(107, 114, 128); // gray-500
        $this->SetFillColor(249, 250, 251); // gray-50
        $this->Cell(0, 7, strtoupper($title), 0, 1, 'L', true);
        $this->SetTextColor(0, 0, 0);
        $this->Ln(1);
    }

    /** Key–value row */
    public function KVRow(string $key, string $value): void
    {
        $this->SetFont('Arial', 'B', 9);
        $this->SetTextColor(107, 114, 128);
        $this->Cell(50, 6, $key . ':', 0, 0, 'L');
        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(17, 24, 39);
        $this->MultiCell(0, 6, $value, 0, 'L');
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// PUBLIC: generateSubmissionPDF()
//
// $submission      — submissions row (includes form_data)
// $form            — forms row
// $stage           — form_stages row (the signature stage)
// $prevApprovers   — array of users rows (people who approved prior stages)
// $signatureImgPath — absolute path to the PNG signature image (temp file)
//
// Returns: absolute path to the generated PDF temp file, or '' on failure.
// ─────────────────────────────────────────────────────────────────────────────

function generateSubmissionPDF(
    array  $submission,
    ?array $form,
    ?array $stage,
    array  $prevApprovers = [],
    string $signatureImgPath = ''
): string {
    try {
        $formName  = $form['title'] ?? $form['name'] ?? 'Form';
        $stageName = $stage['stage_name'] ?? $stage['name'] ?? 'Signature Stage';

        $formData = $submission['form_data'] ?? [];
        if (is_string($formData)) {
            $formData = json_decode($formData, true) ?? [];
        }

        $pdf = new AuroraPDF('P', 'mm', 'A4');
        $pdf->formName  = $formName;
        $pdf->stageName = $stageName;
        $pdf->SetMargins(15, 30, 15);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->AddPage();
        $pdf->SetFont('Arial', '', 10);

        // ── Title ─────────────────────────────────────────────────────────────
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->SetTextColor(17, 24, 39);
        $pdf->Cell(0, 10, $formName, 0, 1, 'L');
        $pdf->SetFont('Arial', '', 10);
        $pdf->SetTextColor(107, 114, 128);
        $pdf->Cell(0, 6, 'Signed Document — Generated ' . date('j F Y, g:i a'), 0, 1, 'L');
        $pdf->Ln(4);

        // ── Submission metadata ───────────────────────────────────────────────
        $pdf->SectionHeading('Submission Details');
        $pdf->KVRow('Form',          $formName);
        $pdf->KVRow('Stage',         $stageName);
        $pdf->KVRow('Submitted by',  $submission['submitter_email'] ?? '—');
        $pdf->KVRow('Submitted at',  $submission['submitted_at']
            ? date('j F Y, g:i a', strtotime($submission['submitted_at']))
            : '—');
        $pdf->Ln(4);

        // ── Form data fields ──────────────────────────────────────────────────
        if (!empty($formData)) {
            $pdf->SectionHeading('Form Data');
            foreach ($formData as $key => $val) {
                $pdf->KVRow(
                    substr((string)$key, 0, 40),
                    substr((string)$val, 0, 500)
                );
            }
            $pdf->Ln(4);
        }

        // ── Approval history ──────────────────────────────────────────────────
        if (!empty($prevApprovers)) {
            $pdf->SectionHeading('Approval History');
            $pdf->SetFont('Arial', '', 9);
            $pdf->SetTextColor(17, 24, 39);
            foreach ($prevApprovers as $ap) {
                $name  = $ap['display_name'] ?? $ap['email'] ?? '—';
                $email = $ap['email'] ?? '';
                $pdf->Cell(0, 6, '✓  ' . $name . ($email ? ' (' . $email . ')' : ''), 0, 1, 'L');
            }
            $pdf->Ln(4);
        }

        // ── Signature ─────────────────────────────────────────────────────────
        $pdf->SectionHeading('Signature');

        if ($signatureImgPath && file_exists($signatureImgPath)) {
            // Place signature image — max 80mm wide
            $imgW   = 80;
            $startY = $pdf->GetY();
            $pdf->Image($signatureImgPath, 15, $startY, $imgW);
            $pdf->SetY($startY + 35); // leave space below image
        } else {
            $pdf->SetFont('Arial', 'I', 9);
            $pdf->SetTextColor(156, 163, 175);
            $pdf->Cell(0, 6, '[Signature data not available]', 0, 1, 'L');
        }

        $pdf->SetFont('Arial', '', 9);
        $pdf->SetTextColor(17, 24, 39);
        $pdf->Cell(0, 6, 'Signed at: ' . date('j F Y, g:i a'), 0, 1, 'L');
        $pdf->Ln(2);

        // Signature line
        $pdf->SetDrawColor(209, 213, 219); // gray-300
        $pdf->Line(15, $pdf->GetY(), 100, $pdf->GetY());
        $pdf->Ln(2);
        $pdf->SetFont('Arial', 'I', 8);
        $pdf->SetTextColor(107, 114, 128);
        $pdf->Cell(0, 5, 'Authorised signature', 0, 1, 'L');

        // ── Output to temp file ───────────────────────────────────────────────
        $tmpPath = sys_get_temp_dir() . '/aurora_signed_' . uniqid() . '.pdf';
        $pdf->Output('F', $tmpPath);

        return $tmpPath;

    } catch (Throwable $e) {
        error_log('[Aurora PDF] generateSubmissionPDF failed: ' . $e->getMessage());
        return '';
    }
}
