<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Ensures every long admin form has a save/submit button near the bottom,
 * not just at the top. This prevents regression where a new form is added
 * without a bottom save button (accessibility requirement).
 *
 * "Near the bottom" = the last btn--primary submit button in the form
 * must appear in the final 35% of the form's raw HTML content.
 */
#[Group('admin')]
#[Group('accessibility')]
final class BottomSaveButtonTest extends TestCase
{
    /**
     * Admin PHP files with long forms that must have a bottom save button.
     * Each entry: [file_path, form_id_or_null, description]
     */
    public static function longFormsProvider(): array
    {
        $dir = $_SERVER['DOCUMENT_ROOT'] . '/admin/';
        return [
            'article-edit: articleForm'         => [$dir . 'article-edit.php', 'articleForm',       'Article editor'],
            'product-edit: main form'           => [$dir . 'product-edit.php', null,                'Product editor'],
            'pages: homeForm'                   => [$dir . 'pages.php',        'homeForm',          'Home page form'],
            'pages: campaignForm'               => [$dir . 'pages.php',        'campaignForm',      'Campaign page form'],
            'pages: impactForm'                 => [$dir . 'pages.php',        'impactForm',        'Impact page form'],
            'pages: centresForm'                => [$dir . 'pages.php',        'centresForm',       'Centres page form'],
            'pages: aboutForm'                  => [$dir . 'pages.php',        'aboutForm',         'About page form'],
            'pages: projectEditForm'            => [$dir . 'pages.php',        'projectEditForm',   'Projects page form'],
            'pages: helpForm'                   => [$dir . 'pages.php',        'helpForm',          'Help page form'],
            'pages: shopForm'                   => [$dir . 'pages.php',        'shopForm',          'Shop page form'],
            'pages: legalPrivacyForm'           => [$dir . 'pages.php',        'legalPrivacyForm',  'Privacy policy form'],
            'pages: legalInfoForm'              => [$dir . 'pages.php',        'legalInfoForm',     'Legal info form'],
            'pages: legalTermsForm'             => [$dir . 'pages.php',        'legalTermsForm',    'Terms form'],
            'campaign: generalForm'             => [$dir . 'campaign.php',     'generalForm',       'Campaign general form'],
            'campaign: rewardsForm'             => [$dir . 'campaign.php',     'rewardsForm',       'Campaign rewards form'],
            'campaign: budgetForm'              => [$dir . 'campaign.php',     'budgetForm',        'Campaign budget form'],
            'campaign: faqForm'                 => [$dir . 'campaign.php',     'faqForm',           'Campaign FAQ form'],
            'campaign: risksForm'               => [$dir . 'campaign.php',     'risksForm',         'Campaign risks form'],
            'campaign: eventForm'               => [$dir . 'campaign.php',     'eventForm',         'Campaign event form'],
            'newsletter-compose: saveForm'      => [$dir . 'newsletter-compose.php', 'saveForm',    'Newsletter compose form'],
            'email-templates: tplForm'          => [$dir . 'email-templates.php',    'tplForm',     'Email templates form'],
        ];
    }

    /**
     * Extracts the raw HTML block of a named form (by id="...") from a PHP file,
     * without executing PHP. Works by finding the form open/close tags as raw strings.
     */
    private function extractFormHtml(string $filePath, ?string $formId): ?string
    {
        $source = file_get_contents($filePath);
        if ($source === false) {
            return null;
        }

        if ($formId === null) {
            // Find the first <form tag
            $start = strpos($source, '<form ');
            if ($start === false) {
                $start = strpos($source, '<form>');
            }
        } else {
            // Find by id attribute (handles id="X" or id='X')
            $start = strpos($source, 'id="' . $formId . '"');
            if ($start === false) {
                $start = strpos($source, "id='" . $formId . "'");
            }
            if ($start === false) {
                return null;
            }
            // Walk back to find the opening <form
            $formStart = strrpos(substr($source, 0, $start), '<form');
            if ($formStart === false) {
                // Form id might be on a button referencing this form — find the actual <form> with this id
                $pattern = '/<form[^>]*id=["\']' . preg_quote($formId, '/') . '["\'][^>]*>/';
                if (preg_match($pattern, $source, $m, PREG_OFFSET_CAPTURE)) {
                    $start = $m[0][1];
                } else {
                    return null;
                }
            } else {
                $start = $formStart;
            }
        }

        // Find the matching </form> by counting nesting
        $depth  = 0;
        $pos    = $start;
        $len    = strlen($source);
        $end    = false;

        while ($pos < $len) {
            $nextOpen  = strpos($source, '<form', $pos);
            $nextClose = strpos($source, '</form>', $pos);

            if ($nextClose === false) {
                break;
            }

            if ($nextOpen !== false && $nextOpen < $nextClose) {
                $depth++;
                $pos = $nextOpen + 5;
            } else {
                $depth--;
                if ($depth <= 0) {
                    $end = $nextClose + strlen('</form>');
                    break;
                }
                $pos = $nextClose + 7;
            }
        }

        if ($end === false) {
            return null;
        }

        return substr($source, $start, $end - $start);
    }

    /**
     * Returns true if there is a save/submit button in the bottom 35% of the form HTML.
     * Matches: btn--primary inside the last third of the raw form source.
     */
    private function hasBottomSaveButton(string $formHtml): bool
    {
        $len        = strlen($formHtml);
        $threshold  = (int)($len * 0.65); // last 35%
        $tail       = substr($formHtml, $threshold);

        // Match btn--primary on a button or input[type=submit]
        return (bool)preg_match('/btn--primary/', $tail);
    }

    #[DataProvider('longFormsProvider')]
    public function testFormHasBottomSaveButton(string $filePath, ?string $formId, string $description): void
    {
        $this->assertFileExists($filePath, "Admin file missing: $filePath");

        $formHtml = $this->extractFormHtml($filePath, $formId);

        $this->assertNotNull(
            $formHtml,
            "Could not extract form" . ($formId ? " id=\"$formId\"" : '') . " from $filePath"
        );

        $this->assertTrue(
            $this->hasBottomSaveButton($formHtml),
            "$description: no btn--primary found in the bottom 35% of the form. " .
            "Add a save button near </form> so users don't have to scroll back to the top."
        );
    }
}
