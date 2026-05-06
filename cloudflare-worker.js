/**
 * Telegram Bot API proxy — Cloudflare Worker.
 *
 * Принимает запросы вида:
 *   https://YOUR-WORKER.workers.dev/bot<TOKEN>/sendMessage
 * и пересылает их в:
 *   https://api.telegram.org/bot<TOKEN>/sendMessage
 *
 * Это обходит блокировку маршрута Timeweb→Telegram, потому что
 * Cloudflare Workers — глобальная сеть, не блокируется в РФ,
 * а сам Cloudflare имеет прямой доступ к api.telegram.org.
 *
 * Установка:
 *   1. https://dash.cloudflare.com/sign-up — регистрация (бесплатно, без карты)
 *   2. Workers & Pages → Create → Hello World → Deploy
 *   3. Edit code → вставить ВЕСЬ этот файл целиком → Save and deploy
 *   4. Скопировать URL вида https://имя.имя-аккаунта.workers.dev
 *   5. В config/config.php заменить TELEGRAM_API_BASE на этот URL
 *
 * Лимиты бесплатного тарифа: 100 000 запросов/день — хватит надолго.
 */

export default {
    async fetch(request) {
        const url = new URL(request.url);

        // Простая защита: разрешаем только пути вида /bot.../что-то
        if (!url.pathname.startsWith('/bot')) {
            return new Response('Telegram Bot API proxy. Use /bot<TOKEN>/<METHOD>', {
                status: 200,
                headers: { 'Content-Type': 'text/plain; charset=utf-8' }
            });
        }

        // Перенаправляем на api.telegram.org с тем же путём, методом и телом
        const target = 'https://api.telegram.org' + url.pathname + url.search;

        try {
            const upstream = await fetch(target, {
                method: request.method,
                headers: filterHeaders(request.headers),
                body: ['GET', 'HEAD'].includes(request.method) ? null : request.body,
                redirect: 'follow'
            });

            // Возвращаем ответ Telegram как есть
            return new Response(upstream.body, {
                status: upstream.status,
                statusText: upstream.statusText,
                headers: upstream.headers
            });
        } catch (err) {
            return new Response(JSON.stringify({
                ok: false,
                error_code: 502,
                description: 'Worker proxy error: ' + err.message
            }), {
                status: 502,
                headers: { 'Content-Type': 'application/json' }
            });
        }
    }
};

function filterHeaders(headers) {
    // Убираем заголовки, которые не должны проксироваться
    const drop = new Set(['host', 'cf-connecting-ip', 'cf-ipcountry', 'cf-ray', 'cf-visitor', 'x-forwarded-for', 'x-forwarded-proto', 'x-real-ip']);
    const out = new Headers();
    for (const [k, v] of headers.entries()) {
        if (!drop.has(k.toLowerCase())) out.set(k, v);
    }
    return out;
}
