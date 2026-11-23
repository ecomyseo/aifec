<?php
/**
 * Pasos fuera de la tienda: lo que hay que hacer en la web de Google, OpenAI, Anthropic, Microsoft, Perplexity y Redsys para
 * que lean el catálogo y el perfil de la tienda. Generado desde utiles/ecom_aifec/guia_externa_datos.py; no se edita a mano.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */
declare(strict_types=1);

namespace Aifec\Modules\Agentic;

use Aifec\Kernel\App;

final class ExternalGuide
{
    /** @return array<int,array{0:string,1:string,2:string,3:array<int,array{0:string,1:?string}>}> */
    public static function sections(): array
    {
        return [
        ['merchant', 'Google Merchant Center (free listings and Gemini shopping)', 'Merchant Center is where Google reads your catalogue. It is free and it is the base for everything else from Google.', [
            ['Open merchants.google.com with the Google account of the business and create the account (country, business name, website).', null],
            ['Verify and claim the website: Merchant Center gives you an HTML file or a tag; upload it to the shop and press verify.', null],
            ['Add your shipping, returns and tax information in the Merchant Center settings. Google rejects products without them.', null],
            ['Go to Products, then Data sources, then add a product source with a scheduled fetch. Choose your country and language.', null],
            ['Paste this address as the file to fetch, set the fetch to daily and save.', '/acp/feed-google.tsv'],
            ['Wait for the first fetch and open the diagnostics: fix what Google reports (usually missing identifiers or images).', null],
        ]],
        ['googleucp', 'Google: pay inside Gemini and AI Mode (UCP and Google Pay)', 'Today this is an early access programme, limited to the United States. Doing the steps early costs nothing and leaves you ready.', [
            ['Finish the Merchant Center steps above first.', null],
            ['Check that your UCP profile is public and valid. This is the address Google will read.', '/.well-known/ucp'],
            ['Create the business profile in the Google Pay and Wallet console (pay.google.com/business/console) and register the website. Google gives you a merchant ID that starts with BCR2DN.', null],
            ['Ask your bank or Redsys to enable Google Pay on your virtual terminal, and ask which value to use as the gateway merchant ID. The Google Pay merchant ID is not the Redsys shop code: you need both.', null],
            ['In Merchant Center open Settings, then UCP integration, and send the interest form. Google tells you if you are selected.', null],
            ['Until Google selects you, buyers still finish on your own checkout. Nothing breaks.', null],
        ]],
        ['openai', 'ChatGPT (OpenAI): product feed and Agentic Commerce Protocol', 'OpenAI reads product feeds from approved merchants. Checkout happens on the merchant site.', [
            ['Open chatgpt.com/merchants and fill in the application form. If you already applied you are on the waiting list; there is nothing else to do until they answer.', null],
            ['When they accept you they ask for a sample feed to validate. This is the feed of the shop.', '/acp/feed.jsonl'],
            ['After validation they take a full snapshot every day. Keep the shop reachable at that address.', null],
            ['The shop already has the ACP profile and purchase API ready. Give OpenAI this profile address if they ask for it.', '/.well-known/acp.json'],
            ['Paying inside the conversation needs a payment provider that accepts the shared payment token (today Stripe). With Redsys only, the buyer finishes on your checkout.', null],
        ]],
        ['claude', 'Claude, ChatGPT and other assistants: connect the shop as a connector', 'Any person can connect the shop to their assistant. No approval from anyone is needed, and the buyer confirms with a code sent by email.', [
            ['Copy the address of the shop connector (MCP).', '/mcp'],
            ['In Claude (claude.ai): open Settings, then Connectors, then Add custom connector, paste the address and save.', null],
            ['In ChatGPT: open Settings, then Connectors (developer mode may be needed), add a custom connector and paste the same address.', null],
            ['Other assistants that support MCP connectors work the same way: they only need the address.', null],
            ['Test it by asking the assistant to search for a product and prepare an order. The shop sends a six-digit code to the buyer; nothing is bought without it.', null],
            ['For assistants that only read pages, the shop also publishes a summary for machines at this address.', '/llms.txt'],
        ]],
        ['microsoft', 'Microsoft Copilot and Bing', 'Copilot reads products through Microsoft Merchant Center.', [
            ['Create a Microsoft Advertising account and open Microsoft Merchant Center from it.', null],
            ['Add your store and verify the website.', null],
            ['Create a product catalogue: you can import it from Google Merchant Center, or use the same file with a scheduled fetch.', '/acp/feed-google.tsv'],
            ['Apply to the Copilot Merchant Program from the same account if the option appears.', null],
        ]],
        ['perplexity', 'Perplexity', 'Perplexity has a merchant programme. It has not published a single feed specification; it accepts a feed in the Google Merchant format.', [
            ['Look for the Perplexity merchant programme sign-up on its website and apply.', null],
            ['When asked for a feed, give the Google Merchant file.', '/acp/feed-google.tsv'],
        ]],
        ['redsys', 'Redsys: what to ask your bank', 'Redsys does not announce payments made by AI agents yet. Asking now shows demand and gets you an answer.', [
            ['Ask your bank: please enable Google Pay on my Redsys virtual terminal. Which connection mode do I need and which value is the gateway merchant ID?', null],
            ['Write to soportevirtual@redsys.es (copy to info@redsys.es) asking whether Redsys will accept Google Pay tokens coming from AI agents and a shared payment token like the one of OpenAI.', null],
            ['Ask also about the agent payment products of Visa and Mastercard and how strong authentication would work.', null],
        ]],
        ];
    }

    public static function html(App $app): string
    {
        $out = '<p>' . e(t('Having the files ready is not enough: each platform decides whether to read them and most ask for a sign-up first. These guides say exactly what to do on their side, in order. Menu names change from time to time; if one does not match, look for the same idea.')) . '</p>';
        foreach (self::sections() as [$id, $title, $intro, $steps]) {
            $out .= '<details style="margin:8px 0;padding:8px 12px;border:1px solid #ddd;border-radius:4px" id="guide-' . e($id) . '"><summary style="cursor:pointer;font-weight:600">'
                . e(t($title)) . '</summary><p>' . e(t($intro)) . '</p><ol>';
            foreach ($steps as [$text, $path]) {
                $out .= '<li>' . e(t($text));
                if ($path !== null) {
                    $out .= ' <code>' . e($app->url()->abs($path, [], '')) . '</code>';
                }
                $out .= '</li>';
            }
            $out .= '</ol></details>';
        }

        return $out;
    }
}
