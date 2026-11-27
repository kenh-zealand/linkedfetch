# LinkedFetch – gemte LinkedIn-opslag

LinkedFetch samler dine gemte LinkedIn-opslag i dit eget dashboard på din WordPress-side (her keha.dk), så du kan søge, filtrere, tagge og læse dem, også efter at LinkedIn har gemt dem væk i en lang liste.

LinkedFetch består af to dele, der arbejder sammen:

| Del | Hvor den kører | Hvad den gør |
| --- | --- | --- |
| **Chrome-udvidelsen** | I din egen Chrome, hvor du er logget ind på LinkedIn | Henter dine gemte opslag fra LinkedIn og sender dem til WordPress |
| **WordPress-pluginet** | På keha.dk | Gemmer opslagene og viser dem i dashboardet på `https://keha.dk/linkedfetch` |

Af Kenneth Hansen, 2026.

> Udvidelsen hed tidligere "LinkedIn til Notion". Fra version 1.0 sender den kun til WordPress. En eksisterende Notion-database bliver liggende, men opdateres ikke længere.

## Indhold

1. [Det får du](#1-det-får-du)
2. [Det skal du bruge](#2-det-skal-du-bruge)
3. [Installation, trin for trin](#3-installation-trin-for-trin)
4. [Første hentning](#4-første-hentning)
5. [Daglig brug](#5-daglig-brug)
6. [Dashboardet](#6-dashboardet)
7. [Tags](#7-tags)
8. [Delte artikler og links](#8-delte-artikler-og-links)
9. [Søg i opslagene med Claude (AI-værktøjer)](#9-søg-i-opslagene-med-claude-ai-værktøjer)
10. [Opdatering til en ny version](#10-opdatering-til-en-ny-version)
11. [Fejlsøgning](#11-fejlsøgning)
12. [Begrænsninger og forbehold](#12-begrænsninger-og-forbehold)
13. [For udviklere](#13-for-udviklere)
14. [Versionshistorik](#14-versionshistorik)

---

## 1. Det får du

- **Alle dine gemte opslag samlet ét sted**: tekst, forfatter, dato, billede og link til opslaget på LinkedIn.
- **Et dashboard** med søgning, filtre (dato, forfatter, tags), liste- eller gittervisning, læsepanel og forhåndsvisning, når du holder musen over et opslag.
- **Automatiske tags** ud fra nøgleord, som du selv kan rette, og egne tags på de enkelte opslag.
- **Delte artikler og links**: når et opslag deler en artikel, gemmes titel, kilde og link, så du kan klikke direkte videre.
- **Klikbare links** i teksten.
- **Daglig kontrol** for nye gemte opslag, hvis du ønsker det.
- **AI-værktøjer**, så Claude kan søge i og opsummere dine opslag gennem din egen MCP-forbindelse til keha.dk.

Dashboardet kan kun ses af administratorer, der er logget ind på WordPress.

## 2. Det skal du bruge

- **Google Chrome** på en computer (udvidelsen virker ikke på telefon).
- **En LinkedIn-konto**, som du er logget ind på i Chrome.
- **En WordPress-side**, hvor du er administrator. WordPress skal køre med "pæne" permalinks (fx `/%postname%/`), og PHP 7.4 eller nyere.
- **Til AI-værktøjerne (valgfrit)**: WordPress 6.9 eller nyere, pluginet *MCP Adapter* og *KEHA MCP* 0.4.0 eller nyere. Se [afsnit 9](#9-søg-i-opslagene-med-claude-ai-værktøjer).

Du skal ikke kunne programmere. Du skal kunne uploade et plugin i WordPress og pakke en zip-fil ud.

## 3. Installation, trin for trin

Begge filer ligger i mappen [`dist`](dist) her på GitHub. Klik på filen og derefter på knappen **Download raw file** (pil ned) for at hente den.

| Fil | Hvad det er |
| --- | --- |
| `dist/linkedfetch-plugin.zip` | WordPress-pluginet |
| `dist/linkedfetch-udvidelse.zip` | Chrome-udvidelsen |

### Trin 1: Installer WordPress-pluginet

1. Log ind på WordPress (fx `https://keha.dk/wp-admin`).
2. Gå til **Plugins → Tilføj nyt → Upload plugin**.
3. Vælg `linkedfetch-plugin.zip`, og klik **Installer nu**.
4. Klik **Aktivér plugin**.
5. Åbn `https://keha.dk/linkedfetch`. Du skal se et tomt dashboard.
   Får du "siden blev ikke fundet", så gå til **Indstillinger → Permalinks** og klik **Gem ændringer** uden at ændre noget. Prøv derefter igen.

### Trin 2: Lav en applikationsadgangskode i WordPress

Udvidelsen logger ind på WordPress med en særlig adgangskode, der kun bruges til den. Din almindelige adgangskode skal **ikke** bruges.

1. I WordPress: gå til **Brugere → Profil** (på engelsk *Users → Profile*).
2. Rul ned til afsnittet **Applikationsadgangskoder**, tæt på bunden af siden.
3. Skriv et navn, fx `LinkedFetch`, og klik **Tilføj ny applikationsadgangskode**.
4. Kopiér koden med det samme. Den ser ud som `abcd efgh ijkl mnop qrst uvwx` og vises kun denne ene gang. Gem den et sikkert sted, indtil trin 4 er gjort.

Kan du ikke finde afsnittet, så se [Fejlsøgning](#11-fejlsøgning).

### Trin 3: Installer Chrome-udvidelsen

Udvidelsen ligger ikke i Chrome Webshop. Den indlæses fra en mappe på din computer.

1. Pak `linkedfetch-udvidelse.zip` ud til en mappe, du **beholder**, fx `Dokumenter\LinkedFetch`. Chrome bruger filerne derfra hele tiden, så mappen må ikke slettes eller flyttes bagefter.
2. Skriv `chrome://extensions` i Chromes adresselinje og tryk Enter.
3. Slå **Udviklertilstand** til (kontakten øverst til højre).
4. Klik **Indlæs upakket**, og vælg mappen `linkedfetch-udvidelse` (den, hvor filen `manifest.json` ligger).
5. LinkedFetch står nu på listen. Klik på puslespilsbrikken i Chromes værktøjslinje og sæt en nål ved LinkedFetch, så ikonet altid er synligt.

### Trin 4: Forbind udvidelsen til WordPress

1. Klik på LinkedFetch-ikonet. Indstillingssiden åbner.
2. Under **1. Forbindelse til keha.dk** udfylder du:
   - **Adresse på WordPress-siden**: `https://keha.dk`
   - **WordPress-brugernavn**: det navn, du logger ind på WordPress med
   - **Applikationsadgangskode**: koden fra trin 2 (mellemrummene må gerne være med)
3. Klik **Gem og test keha.dk**. Der skal stå "Forbindelsen virker" og hvor mange opslag, der ligger på keha.dk.

## 4. Første hentning

1. Sørg for, at du er logget ind på LinkedIn i Chrome.
2. Klik **Hent alle gemte opslag** i udvidelsens indstillinger.
3. LinkedIn åbner i en ny fane med en mørk statusskærm, der viser, hvor mange opslag der er gennemgået. **Lad fanen være forrest**, til der står "Færdig". Chrome bremser faner i baggrunden kraftigt.
4. Klik **Åbn dashboard** på statusskærmen.

Hentningen tager nogle minutter. Opslag, der deler en artikel eller et link, tager lidt længere (se [afsnit 8](#8-delte-artikler-og-links)).

Du kan trygt køre **Hent alle gemte opslag** igen senere. Opslag, der allerede ligger på keha.dk, springes over, og der laves aldrig dubletter.

## 5. Daglig brug

- **Tjek for nye nu**: henter kun de nyeste gemte opslag. Den stopper, når den har set 15 opslag i træk, der allerede ligger på keha.dk.
- **Automatisk én gang i døgnet**: sæt flueben under **3. Automatisk**. Kontrollen kører kun, når Chrome er åben, og du er logget ind på LinkedIn. Fanen lukkes selv bagefter.
- **Stop**: afbryder en kørsel. Nye opslag, der er fundet, men ikke sendt, sendes ikke, så numrene ikke kommer i forkert rækkefølge. Kør blot igen.
- **Log**: nederst i indstillingerne kan du se, hvad udvidelsen har gjort.

Hvert opslag får et løbenummer (**Nr**). Det ældste gemte opslag er nr. 1, og nye får højere numre.

## 6. Dashboardet

Dashboardet ligger på `https://keha.dk/linkedfetch`. Det har tre kolonner:

| Kolonne | Indhold |
| --- | --- |
| **Venstre: filtre** | Opslagsdato, tags, forfatter, kun med billede, og din bruger med **Log ud** |
| **Midten: opslag** | Søgefelt, sortering, **Liste** eller **Gitter** og selve opslagene |
| **Højre: læsepanel** | Hele opslaget, når du klikker på det, med billede, delt artikel og tags |

### Søgning og filtre

- **Søgefeltet** finder ord i opslagets tekst, forfatternavnet og titlen på en delt artikel. Fundne ord markeres med gult.
- **Opslagsdato**: alle, seneste 7 dage, 30 dage, 3 måneder, seneste år, i år eller dine egne datoer.
- **Tags**: klik på et eller flere tags. **Et af dem** viser opslag med mindst ét af de valgte, **Alle** kun opslag med dem alle. **Uden tags** finder opslag, der ikke har fået et tag. Tallene ved hvert tag viser, hvor mange opslag du får med de øvrige filtre.
- **Forfatter**: de 12 forfattere, du har gemt flest opslag fra. Skriv i feltet for at finde andre.
- **Sortering**: senest gemt, nyeste opslag eller ældste opslag.
- Over listen ser du de valgte filtre. Klik **×** for at fjerne et, eller **Ryd alle**.

### Liste og gitter

- **Liste**: kompakte kort med uddrag, tags og et lille billede.
- **Gitter**: større kort med billedet øverst og tags lagt hen over billedet.
- Alle kort har samme størrelse. Opslag uden billede får et felt med forfatterens initialer.
- Er der flere tags, end der er plads til, vises de resterende som **+n**. Hold musen over **+n** for at se dem.
- Valget huskes til næste gang i samme browser.

### Læsning

- **Klik** på et opslag for at åbne det i læsepanelet. Herfra kan du gå til **Forrige** og **Næste**, åbne opslaget på LinkedIn og rette tags.
- **Hold musen stille** over et opslag i et halvt sekund for at se hele opslaget i en popup. Du kan flytte musen ind i popup'en og scrolle. Popup'en virker kun med mus og vises ikke, mens læsepanelet er åbent.
- **Links** i teksten kan klikkes og åbner i en ny fane.

### Tastatur

| Tast | Virkning |
| --- | --- |
| `/` | Hop til søgefeltet |
| `↓` / `j` | Næste opslag |
| `↑` / `k` | Forrige opslag |
| `Enter` | Åbn det markerede kort |
| `Esc` | Luk popup, forlad søgefeltet eller luk læsepanelet |

Dashboardet følger computerens lyse eller mørke tema.

## 7. Tags

### Automatiske tags

Pluginet har 18 standard-tags, fx *Prompting*, *AI-agenter*, *Eksamen og snyd*, *Feedback*, *Didaktik og læringsdesign* og *Videregående uddannelse*. Hvert tag har en række nøgleord. Når et nøgleord står i opslagets tekst eller i titlen på en delt artikel, får opslaget tagget.

Regler for nøgleord:
- Et nøgleord matcher **begyndelsen af et ord**. "agent" matcher både "agenter" og "agentic".
- Nøgleord på **3 tegn eller færre** skal stå som et helt ord. "cv" matcher ikke "cvr".
- Der skelnes ikke mellem store og små bogstaver.

### Ret tags og nøgleord

Klik **Administrer tags** i venstre kolonne. Her kan du ændre navn, farve og nøgleord (adskilt med komma), oprette nye tags og slette tags. Når du gemmer, opdateres de automatiske tags på alle opslag.

### Tags på det enkelte opslag

Åbn opslaget i læsepanelet. Klik på tags for at sætte eller fjerne dem, eller skriv et nyt tag og klik **Opret og sæt på**.

Når du selv har ændret tags på et opslag, bliver dit valg stående. De automatiske tags rører det ikke længere. Vil du tilbage til de automatiske, så klik **Brug automatiske tags igen** i læsepanelet.

## 8. Delte artikler og links

Mange opslag deler en artikel eller et link. Det kan være en LinkedIn-artikel eller en side som kp.dk, uddannelsesmonitor.dk eller en blog. LinkedFetch gemmer:

- **Titel** og **kilde** (fx "Ove Christensen on LinkedIn" eller "oneusefulthing.org")
- **Linket** til artiklen

De vises som en boks under opslagets tekst i læsepanelet og popup'en. Klik på boksen for at åbne artiklen.

Sådan bliver det hentet:
- Titel og kilde følger med i LinkedIns liste over gemte opslag.
- Linket står ikke i listen. Udvidelsen henter det fra selve opslagets side, men kun for opslag med delt indhold, og med 1-2 sekunders pause mellem hvert. Det sker for nye opslag og for opslag, hvor keha.dk mangler oplysningerne.
- Kan linket ikke findes, peger boksen på opslaget på LinkedIn, hvor linket står. Det sker også, når kilden mangler, fx ved delte dokumenter.

**Opslag hentet før version 0.10** får titel, kilde og link ved næste **Hent alle gemte opslag**. Det kræver kun én fuld hentning.

## 9. Søg i opslagene med Claude (AI-værktøjer)

Pluginet har fire værktøjer, som Claude kan bruge, når keha.dk er forbundet som connector via KEHA MCP. Værktøjerne kan kun **læse**, ikke ændre, og de kræver, at du er administrator.

| Værktøj | Hvad det gør |
| --- | --- |
| `linkedfetch/overview` | Antal opslag, periode, alle tags med antal og de forfattere, du har gemt flest opslag fra |
| `linkedfetch/search-posts` | Søgning med fritekst, forfatter, tags (et af / alle), periode og kun med billede. Giver uddrag eller hele teksten, side for side |
| `linkedfetch/get-posts` | Hele teksten for op til 25 udvalgte opslag |
| `linkedfetch/list-authors` | Forfattere med antal opslag og datoen for deres nyeste opslag |

Eksempler på spørgsmål til Claude:
- "Hvor mange opslag har jeg gemt fra Marc Kluge?"
- "Opsummer mine gemte opslag om eksamen og AI fra det seneste halve år."
- "Hvilke forfattere skriver mest om feedback?"

Sådan sætter du det op:
1. Installer *MCP Adapter* og *KEHA MCP* 0.4.0 eller nyere på WordPress ([kenh-zealand/wordpress_mcp](https://github.com/kenh-zealand/wordpress_mcp)).
2. Tilføj keha.dk som connector i Claude (se vejledningen i KEHA MCP).
3. Har du forbundet før, så afbryd og forbind igen, eller start en ny chat, så Claude ser de nye værktøjer.

Under **Indstillinger → KEHA MCP** i WordPress kan du se, om værktøjerne er med. Med LinkedFetch 0.4.0+ og KEHA MCP 0.4.0+ står der **Værktøjer (32)**.

Uden KEHA MCP virker pluginet og dashboardet helt som normalt.

## 10. Opdatering til en ny version

### WordPress-pluginet

1. Hent den nye `dist/linkedfetch-plugin.zip`.
2. I WordPress: **Plugins → Tilføj nyt → Upload plugin**, vælg filen og klik **Installer nu**.
3. Klik **Erstat nuværende med uploadet**.

Dine opslag, tags og billeder bevares. Kræver en version nye felter i databasen, tilføjes de automatisk.

### Chrome-udvidelsen

1. Hent den nye `dist/linkedfetch-udvidelse.zip`, og pak den ud.
2. Kopiér filerne **oven i** din eksisterende udvidelsesmappe, så de gamle filer erstattes.
3. Gå til `chrome://extensions`, og klik på den runde genindlæs-pil på LinkedFetch.
4. Tjek, at versionsnummeret er det nye.

Dine indstillinger (adresse, brugernavn og applikationsadgangskode) bevares.

**Fra "LinkedIn til Notion" til LinkedFetch 1.0:** Opdater på samme måde. Den gemte Notion-nøgle og de øvrige Notion-indstillinger slettes automatisk fra udvidelsen. Klik **Gem og test keha.dk** én gang for at kontrollere forbindelsen.

## 11. Fejlsøgning

| Problem | Løsning |
| --- | --- |
| `keha.dk/linkedfetch` giver "siden blev ikke fundet" | **Indstillinger → Permalinks → Gem ændringer** i WordPress |
| "keha.dk svarede 401" ved test | Brugernavn eller applikationsadgangskode er forkert. Lav en ny applikationsadgangskode, og indsæt den igen |
| "keha.dk svarede 403" | Brugeren er ikke administrator, eller et sikkerhedsplugin blokerer `/wp-json/linkedfetch/`. Tillad stien i sikkerhedspluginet |
| "keha.dk svarede 404" ved test | LinkedFetch-pluginet er ikke aktiveret på WordPress |
| Afsnittet **Applikationsadgangskoder** mangler i WordPress | Siden skal køre på https. Nogle sikkerhedsplugins slår funktionen fra. Slå den til i sikkerhedspluginet |
| "LinkedIn sendte dig videre. Er du logget ind på LinkedIn?" | Log ind på LinkedIn i samme Chrome, og prøv igen |
| Hentningen går meget langsomt eller står stille | Hold LinkedIn-fanen forrest. Kontrollér, at computeren ikke går i dvale |
| Der kommer ingen opslag med | LinkedIn kan have ændret sit datakald. Klik **Opsnap LinkedIns datakald** under *Fejlsøgning* i indstillingerne, klik **Kopiér diagnose**, og send resultatet til den, der vedligeholder udvidelsen |
| Billeder mangler på nogle opslag | Kør **Hent alle gemte opslag** igen. Manglende billeder udfyldes |
| Delt artikel uden link | Linket kunne ikke findes på opslagets side. Boksen peger i stedet på opslaget på LinkedIn |
| Claude kan ikke se LinkedFetch-værktøjerne | Tjek **Indstillinger → KEHA MCP** (skal vise 32 værktøjer). Afbryd og forbind keha.dk i Claude igen, og start en ny chat |
| Den daglige kontrol kører ikke | Chrome skal være åben, og du skal være logget ind på LinkedIn. Fluebenet under **3. Automatisk** skal være sat |

## 12. Begrænsninger og forbehold

- **LinkedIn kan ændre sine datakald** uden varsel. Så kan hentningen holde op med at virke, indtil udvidelsen er tilpasset. Udvidelsen prøver i så fald en langsommere metode, der læser siden direkte. Den henter ikke delte artikler og links.
- **LinkedIns brugeraftale** forbyder automatisk indsamling. LinkedFetch henter kun dine egne gemte opslag, i roligt tempo, og kun når du selv starter den eller har slået den daglige kontrol til. Brugen er på eget ansvar.
- **Billederne** ligger i `wp-content/uploads/linkedfetch` som WebP-filer med tilfældige navne. De kan ikke findes uden at kende navnet, men er ikke beskyttet med login. Teksterne kan kun ses i dashboardet af administratorer.
- **Opslagets dato** regnes ud fra LinkedIns opslags-ID. Det er det tidspunkt, opslaget blev lavet, ikke da du gemte det.
- **Udvidelsen virker kun i Chrome på en computer** og kun, mens Chrome er åben.

## 13. For udviklere

### Filer

| Fil | Indhold |
| --- | --- |
| `manifest.json` | Udvidelsens manifest (Manifest V3) |
| `background.js` | Styrer kørsler, daglig alarm og afsendelse til WordPress |
| `content.js` | Kører på LinkedIns side: henter opslag via LinkedIns datakald, slår links til delt indhold op, viser statusskærmen |
| `hook.js` | Opsnapper LinkedIns datakald (bruges til fejlsøgning og til at lære kaldet) |
| `options.html`, `options.js` | Indstillingssiden |
| `wordpress-plugin/linkedfetch/linkedfetch.php` | WordPress-pluginet: database, REST-API, dashboard og AI-værktøjer |
| `dist/` | Færdige zip-filer til installation |
| `tests/abilities.php` | Test af pluginets REST-ruter og AI-værktøjer mod en SQLite-testdatabase |

### REST-API (pluginet)

Alle ruter ligger under `/wp-json/linkedfetch/v1/` og kræver en bruger med `manage_options`. Udvidelsen logger ind med Basic Auth og en applikationsadgangskode.

| Rute | Metode | Bruges til |
| --- | --- | --- |
| `ids` | GET | Kendte opslag (`ids`), opslag uden billede (`noImage`), opslag hvor delt indhold ikke er tjekket (`needShare`), højeste Nr og pluginets version |
| `posts` | POST | Op til 100 opslag. Nye indsættes med automatiske tags. Eksisterende får manglende billede og delt indhold udfyldt |
| `post-tags` | POST | Sæt tags på ét opslag, eller vend tilbage til automatiske tags |
| `tags` | POST | Gem hele tag-listen (navn, farve, nøgleord). Omdøbninger følger med på manuelt taggede opslag |

Felter i et opslag sendt til `posts`: `id` (`urn:li:activity:…`), `nr`, `author`, `text`, `url`, `date` (ÅÅÅÅ-MM-DD), `image` (kun billeder fra `licdn.com` accepteres), og for delt indhold `shareChecked`, `shareTitle`, `shareSource`, `shareUrl`.

### Database

Tabellen `{prefix}linkedfetch_posts` har kolonnerne `id`, `post_urn` (unik), `nr`, `author`, `text`, `url`, `posted`, `image`, `tags` (JSON-liste), `tags_locked`, `share_title`, `share_source`, `share_url`, `share_checked` og `fetched`. Tag-definitionerne ligger i WordPress-indstillingen `linkedfetch_tags`. Databaseversionen står i `linkedfetch_db_version`, og ændringer i tabellen laves automatisk med `dbDelta`, når den er ændret.

### AI-værktøjer

Værktøjerne registreres med WordPress' Abilities API (`wp_register_ability`, kategorien `linkedfetch`) og lægges på KEHA MCP-serveren via filteret `keha_mcp_ability_names`. De kan også kaldes direkte på `/wp-json/wp-abilities/v1/abilities/linkedfetch/<navn>/run`.

### Test

```
php tests/abilities.php
```

Kræver PHP med `pdo_sqlite` og `mbstring`. Testen bruger lette WordPress-stubs og en SQLite-database i hukommelsen. Den erstatter ikke en afprøvning på en rigtig WordPress-installation.

### Pak zip-filerne

Plugin-zip'en skal indeholde mappen `linkedfetch/` med `linkedfetch.php`. Udvidelses-zip'en skal indeholde mappen `linkedfetch-udvidelse/` med `manifest.json`, `background.js`, `content.js`, `hook.js`, `options.html` og `options.js`.

### Licens

WordPress-pluginet er udgivet under GPL-2.0-or-later (se pluginets filhoved).

## 14. Versionshistorik

### Chrome-udvidelsen

| Version | Ændring |
| --- | --- |
| 1.0.0 | Navnet er nu LinkedFetch. Notion er fjernet, og udvidelsen sender kun til WordPress. Gemt Notion-nøgle slettes ved opdatering |
| 0.10.0 | Titel, kilde og link på delte artikler og links. Efterhentning til eksisterende opslag |
| 0.9.1 | Sender ikke cookies til keha.dk, så applikationsadgangskoden altid bruges |
| 0.9.0 | Eget dashboard på keha.dk via WordPress-pluginet |
| 0.8.0 | Billeder gemmes (Notion) |
| 0.7.0 | Dato for opslaget |
| 0.6.0 | Hurtigere hentning via LinkedIns datakald |
| 0.5.1 | Første version: "LinkedIn til Notion" |

### WordPress-pluginet

| Version | Ændring |
| --- | --- |
| 0.5.0 | Delte artikler og links: gemmes, vises som boks, indgår i søgning og automatiske tags |
| 0.4.5 | Klikbare links i læsepanel og popup |
| 0.4.4 | Forhåndsvisning af hele opslaget, når musen holdes over |
| 0.4.3 | Listen viser så mange tags, som der er plads til |
| 0.4.2 | Tags hen over billedet i gittervisningen |
| 0.4.1 | Alle kort i samme størrelse, initialer ved opslag uden billede |
| 0.4.0 | Skrivebeskyttede AI-værktøjer via KEHA MCP |
| 0.3.2 | Tydeligere søgefelt |
| 0.3.1 | Indlogget bruger og Log ud i dashboardet |
| 0.3.0 | Nyt layout med filterkolonne, liste/gitter og læsepanel |
| 0.2.0 | Tags og filtre |
| 0.1.0 | Første version af dashboardet |
