# Moje masáže · Dotyk & klid

Web: https://moje-masaze.jirijanousek.cz/
Zdroj: https://github.com/George-Pura-Vida/moje-masaze

## Funkce

- Responzivní web s ukotvenou hlavičkou a mobilním hamburger menu.
- Čeština, slovenština, angličtina, španělština a zjednodušená čínština.
- Masážní služby, ceník, rezervace, dárkové poukazy, konzultace a profil maséra.
- 60 minut za 2 000 Kč, 180 minut za 3 000 Kč; balíček 2× sleva 5 %, balíček 3× sleva 10 %.
- Základní cena CZK, možnost EUR podle referenčního kurzu ČNB pouze po aktivaci ověřeného eurového účtu.
- QR bankovní platby s jedinečným VS, bankovní párování z Fio API a přehledné grafické e-maily.
- Nezaplacená rezervace zůstává ve stavu čeká na platbu a její termín se potvrzuje samostatně.
- Tisknutelné a čerpatelné dárkové poukazy po bankovním potvrzení platby.
- Privátní administrace termínů, objednávek a čerpání poukazů.

## WebKitty — nasazení

Projekt používá PHP 8.2+, PDO SQLite a cURL.

1. Git Version Control: repo George-Pura-Vida/moje-masaze, větev main, cílový adresář moje-masaze.
2. V nastavení PHP povol PDO SQLite a cURL.
3. Vytvoř mimo veřejný kořen webu soukromou složku jako sourozence adresáře projektu. Výchozí cesta aplikace je ../moje-masaze-private, práva složky 0700.
4. Nastav bankovní tokeny a administrátorské heslo výhradně v serverové konfiguraci nebo v soukromých souborech. Nikdy je nedávej do GitHubu.
5. Zapni HTTPS a stiskni Deploy ve WebKitty. Pokud neexistuje explicitně nakonfigurovaný auto-deploy, samotné commity GitHub web neaktualizují.

## Soukromá konfigurace

Ve výchozí soukromé složce ../moje-masaze-private může být settings.json (práva 0600):

~~~json
{
  "MASSAGE_ADMIN_EMAIL": "jgjanousek@gmail.com",
  "MASSAGE_EUR_ENABLED": "0",
  "MASSAGE_EUR_FIO_CONFIGURED": "0",
  "MASSAGE_EUR_IBAN": "",
  "MASSAGE_EUR_BIC": "FIOBCZPPXXX"
}
~~~

Hodnoty lze nastavit také serverovými proměnnými prostředí. Aktivuj EUR až po ověření EUR účtu a samostatného tokenu pro čtení.

- CZK Fio API token: proměnná MASSAGE_FIO_TOKEN pouze pro CLI, případně soukromý fio-token.txt (0600).
- EUR token: proměnná MASSAGE_EUR_FIO_TOKEN pouze pro CLI, případně soukromý fio-token-eur.txt (0600).
- Admin heslo: proměnná MASSAGE_ADMIN_PASSWORD_HASH nebo soukromý admin-hash.txt (0600), obsahuje pouze hash hesla, nikdy heslo samotné.
- Volitelný adresář soukromých dat přes proměnnou MASSAGE_PRIVATE_DIR.

Interaktivní vytvoření hashe v CLI:

~~~bash
php -r '$p=trim(fgets(STDIN)); echo password_hash($p,PASSWORD_DEFAULT),PHP_EOL;'
~~~

Zajisti soukromí obrazovky při zadávání hesla.

## Scheduled Tasks / Cron (každých 5 minut)

Na WebKitty nastav každých 5 minut (minute */5, hour/day/month/weekday *):

~~~bash
/usr/bin/php84 /home/sites/1a/1/1f8017897e/moje-masaze/cron/fio-sync.php
~~~

Tato cesta odpovídá dříve dodanému projektu. Pokud WebKitty používá jinou absolutní cestu, musíš ji změnit. Cron ověří IBAN účtu, měnu, přesnou částku, referenci, datum a Fio bankovní ID. Současně odešle potvrzovací e-maily z fronty.

Bezpečný diagnostický příkaz pro Test Command:

~~~bash
/usr/bin/php84 /home/sites/1a/1/1f8017897e/moje-masaze/cron/status.php
~~~

Ten pouze přečte stav PHP, privátního úložiště, tokenových souborů a fronty, nesynchronizuje banku a neodesílá e-maily.

## Správa

URL správce: https://moje-masaze.jirijanousek.cz/admin.php

Bez administrátorského hashe se nelze přihlásit. Administrátor může potvrzovat termíny, zrušit či dokončit masáž a označit čerpání uhrazených poukazů. Platební stav není možné ručně změnit na zaplaceno.

Zákaznické platební odkazy na payment.php obsahují nepředvídatelný token, QR a ruční platební údaje. Odkaz na voucher.php je funkční pouze po ověřené bankovní platbě.

## Testy

GitHub Actions automaticky spouští kontrolu syntaxe všech PHP a JS souborů, unit testy cen, slev, objednávek, ochrany CSRF, poukazů a administrace, plus úplnost překladů. Testy nepotvrzují nasazení ani doručení reálných e-mailů.

Před ostrým provozem otestuj na WebKitty:
1. PHP, SQLite, HTTPS, zápis do soukromé složky a správnou doménu.
2. Nastavení SPF/DKIM/DMARC a skutečné doručení e-mailů, protože úspěch PHP mail() neznamená doručení do schránky.
3. Vytvoření CZK QR, skutečnou kontrolovanou platbu, přiřazení podle VS a samostatné zprávy klientovi i administrátorovi bez duplicit.
4. EUR až po nastavení ověřeného IBAN a EUR Fio tokenu. Některé banky neotevřou EUR SPD QR, proto existují i ruční SEPA údaje.
5. Právní podmínky storna, refundací, doby platnosti poukazů a pravidla GDPR.
6. Vlastní profilovou fotografii maséra. Nyní web používá profilový avatar připojeného GitHub účtu, který je nutné ověřit nebo nahradit.

## Dokumentace třetích stran

Fio API: https://www.fio.cz/docs/cz/API_Bankovnictvi.pdf
QR Platba: https://qr-platba.cz/pro-vyvojare/specifikace-formatu/
Kurz ČNB: https://www.cnb.cz/cs/casto-kladene-dotazy/Kurzy-devizoveho-trhu-na-www-strankach-CNB/


## Automatický Deploy GitHub → WebKitty (každých 5 minut)

Oficiální WebKitty Git Version Control popisuje ruční Deploy. Tento projekt má připravený skript cron/auto-deploy.sh pro automatickou aktualizaci.

**Předpoklad:** Git checkout moje-masaze je zároveň veřejný dokumentový kořen subdomény. Pokud repozitář leží jinde a WebKitty používá oddělenou publikovanou složku, je třeba nasazovací mechanismus upravit.

Postup:
1. Nejprve jednou ručně Deploy z Git Version Control, aby byl soubor cron/auto-deploy.sh dostupný na serveru.
2. Web Tools → Scheduled Tasks → Add Task.
3. Command: /bin/bash /home/sites/1a/1/1f8017897e/moje-masaze/cron/auto-deploy.sh
4. Minute: */5; Hour, Day, Month, Weekday: *.
5. Zkontroluj absolutní cestu podle Repository Path ve WebKitty a spusť Test Command.

Bezpečnost: Skript kontroluje pouze origin George-Pura-Vida/moje-masaze a větev main, neprovádí force ani reset, odmítá přepsat ručně upravené sledované soubory a nasazuje jen přesně ten commit, který prošel GitHub Actions workflow test.yml. Lokální bankovní tokeny, SQLite a hash hesla se musí ukládat mimo veřejnou složku. Webové volání /cron/ je zakázáno přes .htaccess.

Tento cron je oddělený od bankovního Fio cronu. Oba mají vlastní úlohu. Pokud WebKitty cron nepovoluje Bash/Git nebo dokumentový kořen není Git checkout, skript bezpečně skončí chybou a bude potřeba jiné řešení.


## Rezervační kalendář a grafické dárkové poukazy (11/2026)

- Ve formuláři lze vybírat datum a hodinu ještě před zaškrtnutím souhlasu. Datum a čas jsou dostupné také pro dárkové poukazy.
- Endpoint slots.php zobrazuje volné časy podle interní databáze rezervací a blokací. Standardní plánované hodiny jsou 09:00–20:00 v časovém pásmu Europe/Prague; mezi masážemi je 30minutová rezerva.
- Neuhrazená rezervace drží vybraný slot maximálně 24 hodin; potvrzené nebo uhrazené rezervace zůstávají zablokované až do dokončení či zrušení.
- Zápis nového požadavku a kontrola obsazenosti probíhá v jedné SQLite zapisovací transakci; souběžné objednávky stejného termínu nejsou přijímány.
- Administrace admin.php umožňuje vložit nebo zrušit časové blokace, schválit přesný termín, aktualizovat termín již zaplaceného poukazu a odeslat aktualizovaný krásný grafický poukaz.
- calendar-feed.php nabízí tajnou iCalendar URL pro odběr potvrzených masáží v Google Kalendáři. Odkaz se ukáže až po přihlášení do administrace. Je to JEDNOSMĚRNÉ napojení; externí události vašeho osobního Google Kalendáře zatím neovlivňují výběr hodin na webu a změny Google může přebírat se zpožděním.
- Po potvrzení bankovní platby systém odešle dárkový poukaz přímo v grafickém HTML e-mailu klientovi: fotografie, jméno obdarovaného, služba, počet masáží, domluvený / preferovaný termín, hodina, identifikační číslo a vstupní QR kód.
- E-mail obsahuje QR jako vložený PNG obrázek, pokud má hosting PHP GD. Bez GD funguje bezpečný lokální SVG endpoint a tisková stránka; vložené e-mailové obrázky může poštovní klient blokovat.
- voucher.php je tisknutelný poukaz A4. Vstupní QR vede na checkin.php, kde se poukaz ověřuje a uplatňuje pouze po přihlášení správce. Uplatnění je omezeno na zakoupený počet masáží a serverový bankovní stav paid.
- QR je vytvářen lokálně v PHP pomocí MIT knihovny vendor/php-qrcode.php, bez externího QR API a bez předávání klientských tokenů třetí straně.
- Pokud datum není domluveno, poukaz správně uvádí Termín dle individuální domluvy; preferovaný termín není označen za potvrzený.
- Pro profesionální předávání voucherů doporučujeme ověřit skutečné doručování obou e-mailů a tisk QR v Chrome, Safari a mobilních bankovních aplikacích. Žádná přijatá platba nebyla simulována jako zaplacená.
