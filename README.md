# LinkedFetch – gemte LinkedIn-opslag

Chrome-udvidelse og WordPress-plugin, der kopierer dine gemte LinkedIn-opslag til dit eget dashboard på keha.dk og kan tjekke for nye én gang i døgnet.

Fra udvidelsens version 1.0 sendes der kun til keha.dk. Notion-delen er fjernet; en eksisterende Notion-database bliver liggende, men opdateres ikke længere.

Af Kenneth Hansen, 2026.

## Hvad den gør

- Åbner LinkedIns side "Gemte indlæg" i din egen Chrome, hvor du allerede er logget ind, og henter opslagene via LinkedIns eget datakald (10 ad gangen).
- Læser hvert opslag: tekst, forfatter, link og billede (480 px, lavformat).
- Delt artikel eller link i et opslag: titel og kilde fra listen over gemte opslag, og selve linket fra opslagets side (kun for opslag med delt indhold, i roligt tempo). Gemmes på keha.dk og vises som en boks i læsepanelet og popup'en.
- Udfylder datoen for opslaget (regnet ud fra opslagets ID).
- Giver hvert opslag et løbenummer "Nr": det ældste gemte opslag er nr. 1, og nye får højere numre.
- Springer opslag over, der allerede er gemt. Du kan derfor trygt køre den igen.
- Viser en statusskærm over LinkedIn-siden undervejs.

### Dashboardet (WordPress-pluginet LinkedFetch)

- Opslagene gemmes i en tabel i WordPress-databasen, og billederne gemmes som WebP i `wp-content/uploads/linkedfetch`.
- Dashboardet ligger på `https://keha.dk/linkedfetch` og kan kun ses af administratorer, der er logget ind.
- Tre kolonner: filtre til venstre, en kompakt liste (eller gitter) i midten og et læsepanel til højre med hele opslaget, billede og tag-redigering.
- Hold musen stille over et kort i et halvt sekund for at se hele opslaget i en popup (kun med mus, og ikke når læsepanelet er åbent). Klik åbner opslaget i læsepanelet.
- Følger systemets lyse eller mørke tema. Tastatur: / søger, pil op/ned (eller j/k) skifter opslag, Esc lukker.
- Filtre: søgning, forfatter, opslagsdato (faste perioder eller egne datoer), tags (et af / alle), uden tags og kun med billede.
- Tags: 18 standard-tags sættes automatisk ud fra nøgleord i teksten. Du kan selv sætte tags på hvert kort, oprette nye tags og rette navne, farver og nøgleord under "Administrer tags". Opslag, du selv har tagget, røres ikke af de automatiske tags.

### AI-værktøjer via KEHA MCP (fra plugin 0.4.0)

Pluginet har fire skrivebeskyttede værktøjer, så Claude kan søge i opslagene gennem forbindelsen til keha.dk uden at skrive databaseforespørgsler:

| Værktøj | Hvad det gør |
| --- | --- |
| `linkedfetch/overview` | Antal opslag, periode, alle tags med antal og de mest gemte forfattere |
| `linkedfetch/search-posts` | Søgning med fritekst, forfatter, tags (et af / alle), periode og kun med billede. Uddrag eller hele teksten, med sider |
| `linkedfetch/get-posts` | Hele teksten for op til 25 opslag ud fra deres id |
| `linkedfetch/list-authors` | Forfattere med antal opslag og dato for deres nyeste opslag |

Værktøjerne kræver administrator og kan ikke ændre noget. De vises i MCP-serveren, når **KEHA MCP 0.4.0** eller nyere er installeret ([kenh-zealand/wordpress_mcp](https://github.com/kenh-zealand/wordpress_mcp)). Uden KEHA MCP virker pluginet som før.

Test: `php tests/abilities.php` kører værktøjerne mod en lille testdatabase.

## Opsætning

### A. WordPress-pluginet på keha.dk

1. Log ind på WordPress og gå til **Plugins → Tilføj nyt → Upload plugin**.
2. Vælg `linkedfetch-plugin.zip` og klik **Installer nu** og derefter **Aktivér**.
3. Gå til **Brugere → Profil**, rul ned til **Applikationsadgangskoder**, skriv fx "LinkedFetch" og klik **Tilføj ny applikationsadgangskode**. Kopiér koden (den vises kun én gang).
4. Hvis `https://keha.dk/linkedfetch` giver "siden blev ikke fundet": gå til **Indstillinger → Permalinks** og klik **Gem** uden at ændre noget.

### B. Chrome-udvidelsen

1. Pak `linkedfetch-udvidelse.zip` ud til en mappe, du beholder.
2. Skriv `chrome://extensions` i adresselinjen, slå **Udviklertilstand** til og klik **Indlæs upakket**. Vælg mappen med `manifest.json`.
3. Klik på udvidelsens ikon. Under **1** udfylder du brugernavn og applikationsadgangskode og klikker **Gem og test keha.dk**.

Opdatering af en installeret udvidelse: læg de nye filer oven i den gamle mappe og klik på genindlæs-pilen under `chrome://extensions`. Indstillingerne bevares.

### C. Første kørsel

1. Klik **Hent alle gemte opslag** og lad LinkedIn-fanen være forrest, til statusskærmen viser "Færdig".
2. Klik **Åbn dashboard**.
3. Sæt evt. flueben i den daglige kontrol for nye opslag.

## Begrænsninger

- **LinkedIn kan ændre sit datakald.** Så falder udvidelsen tilbage til at læse siden (langsommere). Kør "Opsnap LinkedIns datakald" og send resultatet til Claude.
- **Den daglige kørsel** sker kun, når Chrome er åben, og du er logget ind på LinkedIn.
- **Billederne på keha.dk** ligger i en offentlig mappe med tilfældige filnavne. De kan ikke findes uden at kende navnet, men er ikke beskyttet med login. Teksterne kan kun ses via dashboardet.
- **LinkedIns brugeraftale** forbyder automatisk indsamling. Udvidelsen henter kun dine egne gemte opslag, i roligt tempo, og når du selv starter den eller har slået den daglige kørsel til. Brugen er på eget ansvar.

## Filer

- `manifest.json`, `background.js`, `content.js`, `hook.js`, `options.html`, `options.js` – Chrome-udvidelsen
- `wordpress-plugin/linkedfetch/linkedfetch.php` – WordPress-pluginet (REST-API, dashboard og AI-værktøjer)
- `dist/linkedfetch-plugin.zip` – færdigpakket plugin til upload i WordPress
- `dist/linkedfetch-udvidelse.zip` – Chrome-udvidelsen, klar til at pakke ud og indlæse
- `tests/abilities.php` – test af AI-værktøjerne
