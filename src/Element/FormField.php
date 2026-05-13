<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Element;

/**
 * Phase 43+46: AcroForm interactive form field.
 *
 * Supported types:
 *  - text (single-line)
 *  - text-multiline (Tx с Multiline flag)
 *  - password (Tx с Password flag, masked input)
 *  - checkbox
 *  - radio-group (single AST → multiple widgets sharing same /T)
 *  - combo (Ch с Combo flag — dropdown)
 *  - list (Ch без Combo — listbox)
 *
 * Не реализовано:
 *  - Signature fields (/Sig).
 *  - JavaScript calculation/validation actions.
 *  - Custom appearance streams (полагаемся на reader default rendering).
 */
final readonly class FormField implements BlockElement
{
    public const TYPE_TEXT = 'text';

    public const TYPE_TEXT_MULTILINE = 'text-multiline';

    public const TYPE_PASSWORD = 'password';

    public const TYPE_CHECKBOX = 'checkbox';

    public const TYPE_RADIO_GROUP = 'radio-group';

    public const TYPE_COMBO = 'combo';

    public const TYPE_LIST = 'list';

    public const TYPE_SIGNATURE = 'signature';

    public const TYPE_SUBMIT_BUTTON = 'submit';

    public const TYPE_RESET_BUTTON = 'reset';

    public const TYPE_PUSH_BUTTON = 'push';

    public const SUPPORTED_TYPES = [
        self::TYPE_TEXT, self::TYPE_TEXT_MULTILINE, self::TYPE_PASSWORD,
        self::TYPE_CHECKBOX, self::TYPE_RADIO_GROUP, self::TYPE_COMBO,
        self::TYPE_LIST, self::TYPE_SIGNATURE,
        self::TYPE_SUBMIT_BUTTON, self::TYPE_RESET_BUTTON, self::TYPE_PUSH_BUTTON,
    ];

    /**
     * @param  list<string>  $options  Choices для combo/list/radio-group.
     *                                   Каждая string — export value/label.
     *                                   Для radio — также используется как
     *                                   visual button label.
     */
    public function __construct(
        public string $name,
        public string $type = self::TYPE_TEXT,
        public string $defaultValue = '',
        public float $widthPt = 200.0,
        public float $heightPt = 20.0,
        public float $spaceBeforePt = 4.0,
        public float $spaceAfterPt = 4.0,
        public ?string $tooltip = null,
        public bool $required = false,
        public bool $readOnly = false,
        public array $options = [],
        // Phase 67: AcroForm JavaScript additional actions (/AA dict).
        // Each — optional script run on event:
        //  - validateScript: run on /V (Validate) — return false to reject input.
        //  - calculateScript: run on /C (Calculate) — derive value from другими fields.
        //  - formatScript: run on /F (Format) — modify display value.
        //  - keystrokeScript: run on /K (Keystroke) — per-keypress filter.
        public ?string $validateScript = null,
        public ?string $calculateScript = null,
        public ?string $formatScript = null,
        public ?string $keystrokeScript = null,
        // Phase 83: button-specific.
        // Caption: button label (для push/submit/reset — defaults к type label).
        public ?string $buttonCaption = null,
        // SubmitURL — для TYPE_SUBMIT_BUTTON, target endpoint.
        public ?string $submitUrl = null,
        // /A click action JavaScript (alternative или supplement to submit/reset).
        public ?string $clickScript = null,
    ) {
        if (! in_array($type, self::SUPPORTED_TYPES, true)) {
            throw new \InvalidArgumentException("Unsupported FormField type: $type");
        }
        $needsOptions = in_array(
            $type,
            [self::TYPE_RADIO_GROUP, self::TYPE_COMBO, self::TYPE_LIST],
            true,
        );
        if ($needsOptions && $options === []) {
            throw new \InvalidArgumentException("$type FormField requires non-empty options[]");
        }
    }
}
