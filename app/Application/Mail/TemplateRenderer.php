<?php

namespace App\Application\Mail;

/**
 * Reemplazo de variables en los textos de los correos. NO compila nada: el texto de una plantilla lo escribe una persona desde
 * la aplicación y jamás se ejecuta como código (compilarlo como plantilla de Blade permitiría ejecutar código en el servidor).
 * Los valores de las variables (nombre de quien invita, nombre de la empresa) los escribe cualquier usuario: siempre se escapan.
 */
final class TemplateRenderer
{
    private const PLACEHOLDER = '/\{([a-z_]+)\}/';

    /** @return list<string> Variables (sin llaves) que usa el texto. */
    public static function placeholders(string $template): array
    {
        preg_match_all(self::PLACEHOLDER, $template, $matches);

        return array_values(array_unique($matches[1]));
    }

    /** Texto plano en una línea (asunto, título, botón): sin saltos ni caracteres de control, así no se inyectan encabezados. */
    public static function line(string $template, array $variables): string
    {
        $text = preg_replace_callback(self::PLACEHOLDER, fn (array $m): string => (string) ($variables[$m[1]] ?? $m[0]), $template);

        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $text));
    }

    /**
     * Cuerpo en Markdown seguro (el correo lo convierte a HTML: el HTML crudo se descarta, y un valor con caracteres de Markdown
     * podría armar enlaces o imágenes). Se escapa TODO (el texto de la plantilla y los valores de las variables) salvo `**negrita**`,
     * que es lo único que admite el texto, más los párrafos (línea en blanco) y los saltos de línea.
     */
    public static function markdown(string $template, array $variables): string
    {
        $text = implode('**', array_map(self::escapeMarkdown(...), explode('**', str_replace("\r\n", "\n", $template))));
        $text = preg_replace_callback(self::PLACEHOLDER, fn (array $m): string => array_key_exists($m[1], $variables) ? self::escapeMarkdown((string) $variables[$m[1]]) : $m[0], (string) $text);

        $paragraphs = preg_split('/\n{2,}/', trim((string) $text)) ?: [];

        return collect($paragraphs)
            ->map(fn (string $p): string => str_replace("\n", "  \n", trim($p))) // dos espacios + salto = salto de línea
            ->implode("\n\n");
    }

    /** Caracteres con significado en Markdown (negrita, cursiva, código, enlaces, imágenes, HTML) y la barra de escape. */
    private static function escapeMarkdown(string $text): string
    {
        return strtr($text, [
            '\\' => '\\\\', '`' => '\\`', '*' => '\\*', '_' => '\\_', '[' => '\\[', ']' => '\\]', '<' => '\\<', '>' => '\\>',
        ]);
    }
}
