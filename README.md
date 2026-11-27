# LinkedIn til Notion – gemte opslag

Chrome-udvidelse, der kopierer dine gemte LinkedIn-opslag til en Notion-database og kan tjekke for nye én gang i døgnet.

Af Kenneth Hansen, 2026.

## Hvad den gør

- Åbner LinkedIns side "Gemte indlæg" i din egen Chrome, hvor du allerede er logget ind.
- Læser hvert opslag: tekst, forfatter og link.
- Opretter én side pr. opslag i Notion-databasen "LinkedIn – gemte opslag", med teksten som indhold.
- Giver hvert opslag et løbenummer i kolonnen "Nr": det ældste gemte opslag er nr. 1, og nye får højere numre. Derfor læses hele listen på LinkedIn færdig, før der skrives til Notion.
- Viser en statusskærm over LinkedIn-siden undervejs: antal gennemgået, fremskridt mod Notion og en færdig-besked med link til Notion.
- Springer opslag over, der allerede ligger i Notion. Du kan derfor trygt køre den igen.
- Der er ingen server imellem. Udvidelsen taler kun med LinkedIn og Notion.

## Opsætning (cirka 10 minutter)

### A. Notion

1. Gå til https://www.notion.so/profile/integrations og opret en ny intern integration, fx med navnet "LinkedIn". Notions menuer kan hedde lidt andet end her.
2. Kopiér integrationens hemmelige nøgle (Internal Integration Secret).
3. Opret en tom side i Notion, fx "LinkedIn".
4. Åbn siden, klik på de tre prikker øverst til højre, vælg "Forbindelser" (Connections) og tilføj integrationen.
5. Kopiér linket til siden: brug "Del" og derefter "Kopiér link".

### B. Chrome

1. Pak zip-filen ud til en mappe, du beholder, fx `Dokumenter\linkedin-til-notion`.
2. Skriv `chrome://extensions` i adresselinjen.
3. Slå "Udviklertilstand" til (øverst til højre).
4. Klik "Indlæs upakket" og vælg mappen.
5. Klik på udvidelsens ikon (find den evt. under puslespilsbrikken). Så åbner indstillingssiden.

### C. Første kørsel

1. Indsæt nøglen og sidelinket, og klik "Gem og opret database".
2. Klik "Hent alle gemte opslag". LinkedIn åbner i en ny fane, som scroller af sig selv.
3. Lad fanen være forrest, til loggen skriver "Færdig". Med cirka 1.000 opslag kan det tage op mod en time. Hvis den stopper, så klik igen. Den fortsætter, hvor den slap.
4. Sæt flueben i "Tjek for nye ... én gang i døgnet", hvis du vil have nye gemte opslag med automatisk.

## Begrænsninger

- **Teksten kan være forkortet.** Listen over gemte opslag viser ikke altid hele teksten. Udvidelsen prøver at folde "…mere" ud, men lange opslag kan blive afkortet. Linket til det fulde opslag er altid med.
- **LinkedIn ændrer sin side.** Udvidelsen finder opslagene ud fra deres links (`/feed/update/urn:li:...`). Hvis LinkedIn ændrer opbygningen, kan det holde op med at virke. Kør i så fald "Diagnose" og send resultatet til Claude.
- **Den daglige kørsel** sker kun, når Chrome er åben, og du er logget ind på LinkedIn.
- **LinkedIns brugeraftale** forbyder automatisk indsamling. Udvidelsen henter kun dine egne gemte opslag, i et roligt tempo og når du selv starter den eller har slået den daglige kørsel til. Brugen er på eget ansvar.
- **Notion-API'et:** Udvidelsen bruger Notion-API-version 2022-06-28. Den virker fortsat med almindelige databaser, men Notion anbefaler at opgradere til nyere versioner.

## Filer

- `manifest.json` – udvidelsens beskrivelse og tilladelser
- `background.js` – taler med Notion og styrer kørslerne
- `content.js` – læser opslagene på LinkedIn-siden
- `options.html` / `options.js` – indstillingssiden
