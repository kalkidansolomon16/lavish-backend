<?php

namespace App\Support;

/**
 * Code 128 (set B) barcode as plain HTML table cells — works in email apps, which don't show SVG.
 * The barcode holds the card number without dashes, like the printed cards and the portal.
 */
class Code128
{
    /** Bar/space widths for the 107 Code 128 symbols (values 0–106; 106 = stop, 7 elements). */
    private const PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    /** Bar widths, alternating bar / space, starting with a bar. */
    public static function widths(string $text): array
    {
        $text = preg_replace('/[^\x20-\x7E]/', '', $text);
        $codes = [104]; // start B
        foreach (str_split($text) as $ch) {
            $codes[] = ord($ch) - 32;
        }
        $sum = 104;
        foreach (array_slice($codes, 1) as $i => $c) {
            $sum += $c * ($i + 1);
        }
        $codes[] = $sum % 103; // check digit
        $codes[] = 106;        // stop

        $widths = [];
        foreach ($codes as $c) {
            foreach (str_split(self::PATTERNS[$c]) as $w) {
                $widths[] = (int) $w;
            }
        }

        return $widths;
    }

    /** An HTML table that draws the barcode (module = px per unit). */
    public static function html(string $text, int $module = 2, int $height = 56): string
    {
        $cells = '';
        foreach (self::widths($text) as $i => $w) {
            $color = $i % 2 === 0 ? '#1c2b2c' : '#ffffff';
            $cells .= '<td style="width:'.($w * $module).'px;height:'.$height.'px;background:'.$color.';padding:0;font-size:0;line-height:0">&nbsp;</td>';
        }

        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;margin:0 auto;background:#fff"><tr>'
            .'<td style="width:'.(10 * $module).'px;background:#fff;font-size:0">&nbsp;</td>'.$cells
            .'<td style="width:'.(10 * $module).'px;background:#fff;font-size:0">&nbsp;</td></tr></table>';
    }
}
