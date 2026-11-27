# LinkedFetch – gemte LinkedIn-opslag

Chrome-udvidelse, der kopierer dine gemte LinkedIn-opslag til dit eget dashboard på keha.dk (WordPress-plugin) og/eller en Notion-database, og kan tjekke for nye én gang i døgnet.

Af Kenneth Hansen, 2026.

## Hvad den gør

- Åbner LinkedIns side "Gemte indlæg" i din egen Chrome, hvor du allerede er logget ind, og henter opslagene via LinkedIns eget datakald (10 ad gangen).
- Læser hvert opslag: tekst, forfatter, link og billede (480 px, lavformat).
- Udfylder datoen for opslaget (regnet ud fra opslagets ID).
- Giver hvert opslag et løbenummer "Nr": det ældste gemte opslag er nr. 1, og nye får højere numre.
- Springer opslag over, der allerede er gemt. Du kan derfor trygt køre den igen.
- Viser en statusskærm over LinkedIn-siden undervejs.

### Destination 1: Dit eget dashboard (WordPress-pluginet LinkedFetch)

- Opslagene gemmes i en tabel i WordPress-databasen, og billederne gemmes som WebP i `wp-content/uploads/linkedfetch`.
- Dashboardet ligger på `https://keha.dk/linkedfetch` og kan kun ses af administratorer, der er logget ind.
- Små kort som udgangspunkt (kan skiftes til store) med forfatter, dato, billede, tags og "Se mere".
- Filtre: søgning, forfatter, opslagsdato (faste perioder eller egne datoer), tags (et af / alle), uden tags og kun med billede.
- Tags: 18 standard-tags sættes automatisk ud fra nøgleord i teksten. Du kan selv sætte tags på hvert kort, oprette nye tags og rette navne, farver og nøgleord under "Administrer tags". Opslag, du selv har tagget, røres ikke af de automatiske tags.

### Destination 2: Notion (valgfri)

- Én side pr. opslag i databasen "LinkedIn – gemte opslag" med kolonnerne Titel, Nr, Forfatter, Link, Dato, Billede, Post-ID og Hentet.
- Billedet uploades til Notion, så det ikke udløber.
- Databasens overskrift viser tidspunktet for seneste opdatering.

## Opsætning

### A. WordPress-pluginet på keha.dk

1. Log ind på WordPress og gå til **Plugins → Tilføj nyt → Upload plugin**.
2. Vælg `linkedfetch-plugin.zip` og klik **Installer nu** og derefter **Aktivér**.
3. Gå til **Brugere → Profil**, rul ned til **Applikationsadgangskoder**, skriv fx "LinkedFetch" og klik **Tilføj ny applikationsadgangskode**. Kopiér koden (den vises kun én gang).
4. Hvis `https://keha.dk/linkedfetch` giver "siden blev ikke fundet": gå til **Indstillinger → Permalinks** og klik **Gem** uden at ændre noget.

### B. Chrome-udvidelsen

1. Pak `linkedin-til-notion.zip` ud til en mappe, du beholder.
2. Skriv `chrome://extensions` i adresselinjen, slå **Udviklertilstand** til og klik **Indlæs upakket**. Vælg mappen med `manifest.json`.
3. Klik på udvidelsens ikon. Under **1a** sætter du flueben i "Send opslag til keha.dk", udfylder brugernavn og applikationsadgangskode og klikker **Gem og test keha.dk**.
4. Notion (**1b**) er valgfri. Fjern fluebenet, hvis du kun vil bruge keha.dk.

### C. Første kørsel

1. Klik **Hent alle gemte opslag** og lad LinkedIn-fanen være forrest, til statusskærmen viser "Færdig".
2. Klik **Åbn dashboard**.
3. Sæt evt. flueben i den daglige kontrol for nye opslag.

## Begrænsninger

- **LinkedIn kan ændre sit datakald.** Så falder udvidelsen tilbage til at læse siden (langsommere). Kør "Opsnap LinkedIns datakald" og send resultatet til Claude.
- **Den daglige kørsel** sker kun, når Chrome er åben, og du er logget ind på LinkedIn.
- **Billederne på keha.dk** ligger i en offentlig mappe med tilfældige filnavne. De kan ikke findes uden at kende navnet, men er ikke beskyttet med login. Teksterne kan kun ses via dashboardet.
- **LinkedIns brugeraftale** forbyder automatisk indsamling. Udvidelsen henter kun dine egne gemte opslag, i roligt tempo, og når du selv starter den eller har slået den daglige kørsel til. Brugen er på eget ansvar.
- **Notion-API'et:** Udvidelsen bruger version 2022-06-28 til sider og databaser og 2026-03-11 til upload af billeder.

## Filer

- `manifest.json`, `background.js`, `content.js`, `hook.js`, `options.html`, `options.js` – Chrome-udvidelsen
- `wordpress-plugin/linkedfetch/linkedfetch.php` – WordPress-pluginet (REST-API og dashboard)
