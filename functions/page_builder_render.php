<?php
// ============================================================================
// PFAD: /functions/page_builder_render.php
// ----------------------------------------------------------------------------
// Safe Renderer für builder_json (whitelist blocks)
// ============================================================================

function pb_h($v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function pb_pad_class(string $pad): string {
    return $pad === 'lg' ? 'p-6' : ($pad === 'sm' ? 'p-3' : 'p-4');
}

function pb_align_class(string $align): string {
    return $align === 'center' ? 'text-center' : ($align === 'right' ? 'text-right' : 'text-left');
}

function pb_safe_href(string $href): string {
    $href = trim($href);
    if ($href === '') return '#';

    // erlaube nur http(s) oder relative links
    if (preg_match('#^https?://#i', $href)) return $href;
    if (str_starts_with($href, '/')) return $href;

    return '#';
}

function pb_render_block(array $blk): string {
    $type = (string)($blk['type'] ?? '');
    $p = is_array($blk['props'] ?? null) ? $blk['props'] : [];

    $pad = pb_pad_class((string)($p['pad'] ?? 'md'));
    $align = pb_align_class((string)($p['align'] ?? 'left'));

    if ($type === 'section') {
        return "<div class='rounded-3xl border border-gray-800/60 bg-gray-950/50 backdrop-blur-lg {$pad} shadow-xl {$align}'>"
            . "<div class='text-gray-200'>"
            . "</div></div>";
    }

    if ($type === 'heading') {
        $lvl = (string)($p['level'] ?? '2');
        $text = pb_h((string)($p['text'] ?? ''));
        $cls = $lvl === '1' ? "text-3xl font-bold" : ($lvl === '3' ? "text-lg font-semibold" : "text-2xl font-semibold");
        return "<div class='{$align} {$cls} text-gray-100'>{$text}</div>";
    }

    if ($type === 'text') {
        $text = pb_h((string)($p['text'] ?? ''));
        return "<div class='{$align} text-gray-300 whitespace-pre-wrap'>{$text}</div>";
    }

    if ($type === 'button') {
        $text = pb_h((string)($p['text'] ?? ''));
        $href = pb_safe_href((string)($p['href'] ?? '#'));
        $variant = (string)($p['variant'] ?? 'primary');

        $cls = $variant === 'secondary'
            ? "inline-flex items-center px-4 py-2 rounded-2xl bg-gray-700 hover:bg-gray-600 transition text-white font-semibold"
            : "inline-flex items-center px-4 py-2 rounded-2xl bg-violet-600 hover:bg-violet-500 transition text-white font-semibold shadow-lg shadow-violet-600/20";

        return "<div class='{$align}'><a class='{$cls}' href='".pb_h($href)."'>".$text."</a></div>";
    }

    if ($type === 'divider') {
        $style = (string)($p['style'] ?? 'line');
        if ($style === 'spacer') return "<div class='h-6'></div>";
        return "<div class='h-px bg-gray-800/70'></div>";
    }

    // Unknown block -> ignore
    return "";
}

function pb_render_builder_json(string $json): string {
    $json = trim($json);
    if ($json === '') return "";

    $data = json_decode($json, true);
    if (!is_array($data)) return "";

    $blocks = $data['blocks'] ?? null;
    if (!is_array($blocks)) return "";

    $out = "";
    foreach ($blocks as $blk) {
        if (!is_array($blk)) continue;
        $out .= "<div class='mb-4'>" . pb_render_block($blk) . "</div>";
    }
    return $out;
}
