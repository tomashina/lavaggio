<?php

namespace App\Support\Mail;

use DOMDocument;
use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;

final class WatchlineMailDesign
{
    /** Inline essential styles so the message also works in clients that remove head CSS. */
    public static function content(string $html): string
    {
        $css = <<<'CSS'
body { font-family:Arial,Helvetica,sans-serif;color:#111111;font-size:15px;line-height:1.7; }
h1,.title { margin:0 0 16px;font-size:28px;line-height:1.25;font-weight:800;letter-spacing:-.6px;color:#111111; }
h2 { font-size:18px;line-height:1.4;margin:24px 0 10px;color:#111111; }
h3 { margin:0 0 8px;font-size:16px; }
p { margin:0 0 16px;line-height:1.7; }
a { color:#0077b5; }
.eyebrow { margin:0 0 12px;color:#0077b5;font-size:11px;font-weight:bold;letter-spacing:1.4px;text-transform:uppercase; }
.intro,.text { color:#444444;font-size:15px;line-height:1.7; }
.muted,.sku { color:#64748b;font-size:12px;line-height:1.6; }
.cta { display:inline-block;margin:12px 0;padding:14px 24px;border-radius:7px;background:#0077b5;color:#ffffff;text-decoration:none;font-size:15px;font-weight:bold;text-align:center; }
.panel,.reference,.statement,.bank,.note { margin:20px 0;padding:18px;background:#edf6fc;border:1px solid #dce8ef;border-radius:8px; }
.statement { border-left:3px solid #0077b5; }
.coupon { margin:24px 0;padding:20px 12px;border:2px dashed #0077b5;border-radius:8px;background:#edf6fc;color:#0077b5;font-size:26px;line-height:1.4;font-weight:bold;letter-spacing:2px;text-align:center;word-break:break-all; }
.details,.meta { width:100%;border-collapse:collapse;margin:20px 0;font-size:14px; }
.details th,.details td,.meta td { padding:10px 6px;border-bottom:1px solid #dce8ef;vertical-align:top;text-align:left;overflow-wrap:anywhere; }
.details th,.label { width:34%;color:#64748b;font-size:12px;font-weight:normal; }
.items { width:100%;border-collapse:collapse;margin:24px 0;font-size:13px; }
.items th { padding:10px 4px;text-align:left;color:#64748b;font-size:11px;border-bottom:1px solid #dce8ef; }
.items td { padding:12px 4px;vertical-align:top;border-bottom:1px solid #dce8ef; }
.img-wrap { width:52px;text-align:center; }
.img { width:52px;height:auto;max-height:80px;object-fit:contain; }
.name { color:#111111;text-decoration:none;font-size:13px;font-weight:bold; }
.num { text-align:right;white-space:nowrap; }
.items .num { text-align:right; }
.mobile-label { display:none; }
.totals { width:100%;max-width:360px;margin-left:auto;border-collapse:collapse;font-size:14px; }
.totals td { padding:9px 5px;border-bottom:1px solid #dce8ef; }
.totals tr:last-child td { color:#111111;font-size:17px;font-weight:bold; }
.k { color:#64748b; }
.bank p { margin:4px 0;font-size:13px; }
.bank-qr img { width:300px;max-width:100%;height:auto; }
.block { margin-top:22px; }
.block p,.pre-line { white-space:pre-line;overflow-wrap:anywhere; }
.center { text-align:center; }
CSS;
        $document = new DOMDocument('1.0', 'UTF-8');
        $inlined = (new CssToInlineStyles)->convert('<!doctype html><html><head><meta charset="utf-8"></head><body>'.$html.'</body></html>', $css);
        @$document->loadHTML($inlined, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $body = $document->getElementsByTagName('body')->item(0);
        $result = '';
        foreach ($body?->childNodes ?? [] as $node) {
            $result .= $document->saveHTML($node);
        }

        return $result;
    }
}
