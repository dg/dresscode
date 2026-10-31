# Audit příkazů `init`, `upgrade` a `migrate`

Audit implementace na revizi `a04f8cbc77b69dc12cd3ce242ad064a5d551fe36`, 31. října 2026.

## Závěr

Nejsilnější část návrhu je společný jazyk rozhodnutí a skutečnost, že `init` měří prostřednictvím stejných pravidel, která potom projekt kontrolují. Dobré je také oddělení překladu cizí konfigurace od měření stylu, explicitní rozlišování rizik oprav a nabídka čitelného, editovatelného plánu upgradu.

Nejslabší část je spolehlivost tvrzení, která příkazy dávají uživateli. Rozsah odhadu není závazným rozsahem zápisu. „Done“ nemusí znamenat dokončenou práci. Přítomnost JUnit souboru se zaměňuje za úspěšné ověření. „Nothing moved“ nemusí znamenat shodu konfigurace s naměřeným stylem. Migrovaná konfigurace nemusí zachovat ani rozsah původního formatteru, ani význam cest vzhledem k místu, kam byla zapsána.

**Doporučuji ponechat engine pravidel, katalog rozhodnutí a základ měření. Výrazně přepracovat orchestrace upgradu, model plánu a jeho běhového stavu, kontrakt ověření testy a import rozsahu cizích konfigurací.** Samotné přejmenování metod nebo rozdělení velké třídy by podstatné problémy nevyřešilo.

## Rozsah a míra ověření

Prošel jsem příkazy, jejich options, tisk výstupů, `Measuring/`, `Upgrade/`, čtení a překlad cizích konfigurací v `Interop/`, migraci suppressions a navazující části loaderu, resolveru, runneru a baseline. Posuzoval jsem také help, příslušné testy, `docs/internals.md` a migrační workflow v README.

Ověření v tomto auditu:

- PHP 8.5.12 na Windows.
- PHPStan celého projektu: bez chyb.
- Nette Tester nad `Console/`, `Measuring/`, `Upgrade/`, `Interop/` a `Engine/Suppression.phpt`: **22 testovacích souborů úspěšných, 82,5 s**. Každý soubor obsahuje více jednotlivých scénářů.
- Samostatné reprodukce chyb přes skutečné `Application`, `PlanRunner`, parser JUnit a čtení konfigurací. Jejich podstatné vstupy a výsledky jsou níže.
- Nespouštěl jsem skutečnou instalaci nebo upgrade závislostí auditovaného projektu. Chování kolem Composeru, selhání zápisu, přerušení procesu a souběhu je tam, kde není uvedena reprodukce, závěrem z konkrétního toku implementace.

Nejde o důkaz úplnosti všech překladových tabulek vůči všem verzím PHP CS Fixer, PHP_CodeSniffer a Pint. Existující testy ověřují značnou část překladů a platnost jejich hodnot; neposkytují obecný důkaz sémantické ekvivalence obou nástrojů. Audit se také netýká správnosti všech jednotlivých opravovacích pravidel knihovny.

Závažnost: **P1** znamená chybný zásah do projektu nebo zásadně nepravdivý výsledek; **P2** významný problém návrhu, použitelnosti nebo odolnosti; **P3** menší nekonzistenci či údržbový dluh. „Reprodukce“ znamená ověření během auditu, „rozbor“ závěr z implementace a „návrh“ vědomou změnu produktu.

## 1. Nejdůležitější nálezy v `upgrade`

### U01 — P1: `--paths` omezuje odhad, ale následný běh mění celý projekt

**Reprodukce.** Projekt má dva soubory s implicitně nullable parametrem. Příkaz:

```shell
dresscode upgrade php 8.4 --paths src/Code.php --run --no-types --offline --jobs 1 --format json
```

opraví `src/Code.php` **i `src/Other.php`**. Druhý soubor nebyl součástí odhadu. Návratový kód 3 v této reprodukci znamená následné čekání na Composer; oba soubory už jsou změněné.

`Estimator::estimate()` zúžení respektuje, `Plan` rozsah nenese a `PlanRunner::runTopic()` znovu hledá všechny nakonfigurované soubory. `--paths` je navíc přijímáno i s `--continue` a `--tasks`, kde se nepoužije.

**Změna:** rozsah musí být součástí plánu a jeho náhledu. Zápis smí použít tentýž rozsah, případně musí změnu rozsahu výslovně označit jako nový plán. Nepoužívané options odmítat.

Zdroj: [UpgradeCommand::offer()](src/Console/Commands/UpgradeCommand.php#L81), [Estimator::estimate()](src/Upgrade/Estimator.php#L85), [PlanRunner::runTopic()](src/Upgrade/PlanRunner.php#L219).

### U02 — P1: chyby souborů se ztrácejí a plán může oznámit úspěch

**Reprodukce:** soubor `<?php function {` a plán s jedním fix krokem skončí jako `RunStop::Done`.

`TopicReporter` sbírá změny a neopravené violations, ale ignoruje `syntaxError`, `failure` a warnings. `finish()` nic nepřevezme. `PlanRunner` zahodí i návratový `RunResult`. Selhání čtení, zápisu, pravidla nebo konvergence tak nemusí zabránit výsledku „UPGRADED“. Obdobně `Estimator::collectTopics()` čte violations, ale do odhadu nepřenáší chyby souborů jako samostatnou nejistotu nebo neúspěch.

**Změna:** zavést výsledek kontroly tématu obsahující změny, úkoly, chyby souborů a diagnostiku. Chyby musí zastavit krok a být vidět v textu i JSON. Odhad s nezkontrolovanými soubory musí být označen jako neúplný.

Zdroj: [TopicReporter](src/Upgrade/TopicReporter.php), [PlanRunner::runTopic()](src/Upgrade/PlanRunner.php#L219), [Estimator::collectTopics()](src/Upgrade/Estimator.php#L163).

### U03 — P1: testovací brána přijímá neplatný či neúplný JUnit jako úspěch

**Reprodukce:** `TestResults::fromJunit('not xml', ...)->findNewFailures(...)` vrací prázdné pole. Stejně dopadne `<testsuites/>` proti baseline s existujícím úspěšným testem.

`collect()` zahazuje exit code, timeout i výstup procesu. `fromJunit()` vrací pro rozbitý XML prázdné výsledky. Zmizelé testy se neporovnávají. `skipped` se počítá jako úspěch. Duplicitní identita testu přepisuje předchozí záznam. Proces může vyprodukovat část XML a pak havarovat; to se neposuzuje jako zvláštní stav.

**Změna:** rozlišit výsledek spuštění a výsledek testů. Požadovat platný report, doložené dokončení procesu a vysvětlenou změnu množiny testů. Exit code 1 při řádném reportu s již známými selháními může být přijatelný; timeout, havárie, neplatný report a nečekaně prázdná sada nikoliv. Uchovat stdout/stderr pro diagnostiku.

Zdroj: [TestResults](src/Upgrade/TestResults.php#L38), [PlanRunner::findBaseline()](src/Upgrade/PlanRunner.php#L151). Test v `Upgrade/PlanRunner.phpt` dokonce výslovně očekává prázdný výsledek pro neplatné XML; současný kontrakt je potřeba změnit, nejen rozšířit testy.

### U04 — P1: snapshot vzniká až po zápisu; obnova není transakce

**Rozbor.** `Runner::processPath()` nejprve zapíše opravený soubor. Teprve následné `reportFile()` volá callback, který ukládá původní text do `Snapshots`. Výpadek mezi zápisem a snapshotem nebo chyba ukládání snapshotu zanechá změnu bez zaručené obnovy.

Obnova je volána při neúspěchu testů, nikoliv obecně při výjimce během tématu nebo kroku. Další běh neřeší přerušený krok před zahájením práce. Zachované snapshoty se klíčují číslem kroku; při opakování se původní obsah pro stejnou cestu už neaktualizuje. Pozdější rollback tak může vrátit i uživatelovu mezitím provedenou změnu. `restore()` nekontroluje, zda obsah stále odpovídá tomu, co napsal upgrade. Chybný index se tiše změní na prázdný.

**Změna:** původní obsah bezpečně uložit **před** prvním zápisem. Použít identitu běhu a plánu, atomický journal a kontrolní součty původního i napsaného obsahu. Při startu řešit nedokončenou transakci. Při obnově cizí změnu nepřepsat; oznámit konflikt a nabídnout uložené verze. Recovery data nemají být obyčejná odmazatelná cache.

Zdroj: [Runner::processPath()](src/Engine/Runner.php#L255), [TopicReporter::reportFile()](src/Upgrade/TopicReporter.php), [Snapshots](src/Upgrade/Snapshots.php), [PlanRunner::run()](src/Upgrade/PlanRunner.php#L74).

### U05 — P1: splněný Composer krok znamená pouze vhodný text constraints

**Rozbor.** `isRaised()` kontroluje `composer.json`. Nekontroluje soulad locku, instalovaných balíčků, `config.platform.php` ani skutečné dokončení update. Uživatel může změnit jen constraint a `--continue` pustí další opravy proti starému vendoru. I neúspěšný update, po kterém by zůstala část stavu změněná, nemá vlastní recovery model.

Naopak knihovní krok s `update: false` žádnou aktualizaci vendoru záměrně nechce. Tyto dva významy tedy nelze uzavřít stejnou otázkou „je zvýšeno?“.

**Změna:** explicitní postconditions podle druhu kroku: požadovaná změna manifestu, požadovaný stav locku, požadovaný stav instalace. Pro PHP ukázat i platformu a rozlišit cílovou verzi od interpreteru, který nástroj spouští. Po změně závislostí navázat v čerstvém procesu.

Zdroj: [PlanRunner::isRaised()](src/Upgrade/PlanRunner.php#L248), [Composer::require()](src/Upgrade/Composer.php), [UpgradeCommand::raiseWithConsent()](src/Console/Commands/UpgradeCommand.php).

### U06 — P1: plán může být „hotový“, přestože zůstaly úkoly

**Reprodukce:** plán s jediným fix krokem `php` nad voláním `strftime()` vrátí `Done` a současně jeden task.

Zastavení na tasks je podmíněno `$raised`. Týká se tedy pouze fix kroku následujícího po Composer kroku. Bridge bez následného zvýšení nebo jiný poslední krok může nechat práci a skončit s nulovým exit code. Stav se přitom zahodí přes `forget()` a tisk oznámí „every step of the plan is done“. Na konci také není společná kontrola, zda pozdější zásah do jiného souboru znovu neotevřel dřívější téma; pevný bod jednotlivého souboru není pevný bod celého plánu.

**Změna:** dokončení je vlastnost celého plánu: požadované postconditions, žádné blokující úkoly, žádné chyby a známý stav ověření. Chce-li produkt připustit dokončení s tolerovanými nálezy, musí jít o pojmenovaný stav s uvedeným důvodem, nikoliv `Done`.

Zdroj: [PlanRunner::run()](src/Upgrade/PlanRunner.php#L74), [RunOutcomePrinter](src/Console/RunOutcomePrinter.php).

### U07 — P2: ruční kroky jsou přeskočeny a neexistující krok je úspěšný

**Reprodukce:** plán obsahující pouze `StepKind::Manual` skončí `Done`; `run($plan, only: 99)` také skončí `Done`, ačkoliv plán krok 99 nemá.

Ruční krok může být legitimně informativní, ale pak není předpokladem dalšího postupu. Současný model ho prezentuje jako krok plánu, jeho splnění nezjistí a závěrem prohlásí všechny kroky za hotové. `--step` navíc neověřuje závislosti na předchozích krocích.

**Změna:** oddělit doporučení od blokujícího ručního kroku. Ten musí mít ověřitelnou podmínku nebo explicitní potvrzení. Neplatné či přeskočené číslo kroku odmítnout před prací; izolované spuštění má ověřit své předpoklady.

Zdroj: [PlanRunner::run()](src/Upgrade/PlanRunner.php#L74), [Plan::findSteps()](src/Upgrade/Plan.php#L50), [UpgradeOptions::$step](src/Console/Commands/UpgradeOptions.php).

### U08 — P1: `--no-types` nevypne typovou analýzu z konfigurace

**Reprodukce:** konfigurace má `typeAnalysis: phpstan`. `upgrade php 8.4 --no-types --offline --jobs 1 --format json` vrátí `types: true`.

`decideTypes()` sice vrátí false, ale `Project::createLayer()` přeloží false na `typeAnalysis: null`. Null znamená nepřepsat předchozí nastavení. V nabídce i plánování tak explicitní přepínač neplatí. Je to příklad obecného problému: „nenastaveno“, „automaticky“ a „vypnuto“ nemají v přenosu nastavení samostatný význam.

**Změna:** explicitní režim `auto`, `off`, `phpstan`, se zachováním významu přes všechny vrstvy a worker serializaci. Parametry testů mají pokrývat kombinaci CLI × konfigurace × dostupnost PHPStan.

Zdroj: [UpgradeCommand::decideTypes()](src/Console/Commands/UpgradeCommand.php#L380), [Project::createLayer()](src/Upgrade/Project.php#L43), [RunnerFactory::resolve()](src/Config/RunnerFactory.php).

### U09 — P2: spustitelný krok neuchovává přesnou hranici změřeného tématu

**Rozbor.** Estimator seskupuje nálezy podle `$name|$phase`, ale `set` uloží jen z prvního nálezu. Pro upgrading data je `$name` celé jméno knihovny. Jeden balíček může mít rozhodnutí různých účelů nebo více verzí. `PlanBuilder` potom do kroku uloží pouze textové jméno tématu. `runTopic()` z něj udělá `--only` nad celým balíčkem a přidá všechna zachovávaná rozhodnutí.

Při běžném neměnném vstupu část hranice bridge/jump vynutí opětovná resolution proti aktuálním verzím. Samotný krok ji ale nenese. Změna constraints či dat mezi odhadem a během může posunout opravy do jiného kroku, aniž se přepočítá a ukáže plán. Výběr celého balíčku také není přesným záznamem konkrétních rozhodnutí, která byla změřena. U témat z různých účelů musí být agregace a výběr pravidel výslovně otestovány; současný model jejich oddělení neumí obecně doložit. Jde o slabinu kontraktu a riziko při změnách vstupů, nikoliv o zde reprodukovaný předčasný zápis modernizace.

**Změna:** téma může být prezentační seskupení. Spustitelný krok má nést konkrétní výběr rozhodnutí či operací, požadovaný rozsah verzí a účel. Nesmí znovu rozšířit úmysl plánu na celý ekosystém.

Zdroj: [Estimator::collectTopics()](src/Upgrade/Estimator.php#L163), [PlanBuilder::createFix()](src/Upgrade/PlanBuilder.php), [Step](src/Upgrade/Step.php), [PlanRunner::runTopic()](src/Upgrade/PlanRunner.php#L219).

### U10 — P1: editovatelný plán nemá skutečné schéma ani dostatečnou identitu

**Reprodukce:** `onyl: [php]` je ignorováno a `skip: falsee` se přetypuje na true. Plán se načte s prázdným `only` a přeskočeným krokem.

`PlanFile::read()` přetypovává místo validace. Neodmítá neznámé klíče, špatné tvary seznamů, neplatné package/version ani nesmyslné kombinace polí druhu kroku. `tests` jiného typu se změní na prázdné. Chybí verze formátu plánu. Verze DressCode a dat nejsou verzí schématu.

Plán také nenese původní rozsah, režim typů, CLI vrstvu, identitu konfigurace nebo dostatečné podmínky návratu. Baseline a snapshoty se vážou převážně na kořen projektu a čísla kroků. Úprava či přenesení plánu může znovu použít stav, který k němu nepatří.

**Změna:** přísné schéma s chybou na konkrétní cestě, verzí formátu, stabilními identitami kroků a identitou účinných vstupů. Cíleně rozhodnout, které změny dovolují pokračování a které vyžadují nový odhad. Neznámý klíč nesmí změnit běh mlčením.

Zdroj: [PlanFile::read()](src/Upgrade/PlanFile.php#L40), [Plan](src/Upgrade/Plan.php), [PlanRunner::getWorkDir()](src/Upgrade/PlanRunner.php).

### U11 — P2: hlavní a rychlé testy sdílejí baseline a uložená baseline může zastarat

**Rozbor.** `findBaseline()` uloží výsledek hlavního příkazu do jediného souboru. Výsledek rychlého příkazu se porovnává proti němu, přestože může mít jiné identity testů nebo jinak velkou sadu. Samostatně zadaný `quickTestCommand` bez `testCommand` se fakticky nepoužije, protože baseline je null. Nepřítomnost `{junit}` se zjistí až spuštěním příkazu.

Baseline nemá otisk příkazu, plánu, konfigurace testování či stavu závislostí. Při pokračování po ručních zásazích se opakovaně používá původní výsledek bez vysvětlení, co ještě srovnává.

**Změna:** každá testovací sada vlastní identitu a baseline. Validovat příkazy při tvorbě plánu, před prvním zápisem. Stav baseline, její původ a přijatá předchozí selhání zahrnout do reportu. Zvlášť rozlišit „není nová regrese“ a „testy procházejí“.

Zdroj: [PlanRunner::run()](src/Upgrade/PlanRunner.php#L74), [PlanRunner::findBaseline()](src/Upgrade/PlanRunner.php#L151), [TestResults](src/Upgrade/TestResults.php).

### U12 — P1: přepis constraints nedodržuje slíbený tvar ani všechny původní meze

**Rozbor.** `raiseConstraints()` zachová jen rozlišení prefixu `>=`; všechno ostatní mění na `^target`. Například `~8.1.0` nebo složený constraint s horní mezí se nepozvedne zachováním původního úmyslu. Může se rozšířit množina povolených budoucích verzí nebo odstranit původní omezení. Přitom dokumentace říká „each in the shape the project writes it“.

`isRaised()` si navíc nejnovější požadovanou větev odvozuje z poslední části rozdělené na `||`, ačkoliv pořadí větví není pořadí verzí. U editovaného plánu jde o slabou náhradu skutečné kontroly množin constraints.

**Změna:** samostatná politika změny constraints. U jednoduchých tvarů přesně definovat transformaci; u složitých ukázat navržený rozdíl a jeho význam. Ověřovat přes Composer semver, nikoliv pořadí textových větví. Neuchovávat zdání, že se libovolný constraint upraví beze změny dalších příslibů.

Zdroj: [PlanBuilder::raiseConstraints()](src/Upgrade/PlanBuilder.php#L96), [PlanRunner::isRaised()](src/Upgrade/PlanRunner.php#L248), `docs/internals.md`, sekce upgrade.

### U13 — P2: odhad Composer změn není náhledem skutečného plánu

**Rozbor.** `Estimator::findMoves()` vždy previewuje `^version`. Skutečný plán může použít `>=version`, knihovní sjednocení constraints a `--no-update`. Rozhodnutí knihovna/aplikace se dělá až po odhadu. `--plan` pak místo odhadu vytiskne pouze plán a ztratí jeho warnings, chybějící data, slepá místa a nejistotu Composer odpovědí.

**Změna:** nejprve sestavit přesné změny manifestu, potom previewovat tytéž operace a tatáž flags. Plán musí obsahovat předpoklady a omezení odhadu. Uživatel spouštějící rovnou `--run` nesmí vidět méně důležitých informací než ten, který spustil prosté `upgrade`.

Zdroj: [Estimator::findMoves()](src/Upgrade/Estimator.php#L297), [UpgradeCommand::offer()](src/Console/Commands/UpgradeCommand.php#L81), [PlanPrinter](src/Console/PlanPrinter.php).

### U14 — P2: `--commit` nemá jednotku odpovídající ověření a pokračování

**Rozbor.** Testuje se stav celého fix kroku, ale commitují se jednotlivá témata, včetně jejich mezistavů, které samostatně testovány nebyly. Composer změny se tímto mechanismem necommitují; další spuštění s `--commit` je může vyhodnotit jako vlastní změny uživatele a odmítnout pokračování.

`Git::hasOwnChanges()` ignoruje untracked soubory. Soubor s již existující uživatelovou prací tak může být poprvé přidán celý do upgrade commitu. Chyba `git status` se vyhodnotí jako „nemá změny“. Po neúspěšném commitu není původní index samostatně obnoven. Hook může být další mutující účastník workflow.

**Změna:** buď commitovat jeden skutečně ověřený krok, nebo ověřovat každou zamýšlenou jednotku commitu. Zavést jasnou politiku Composer změn, indexu a untracked souborů. Chyba zjišťování stavu není čistý strom. Prověřit workflow v repozitáři s již staged prací i selhávajícím hookem.

Zdroj: [PlanRunner::commitStep()](src/Upgrade/PlanRunner.php#L268), [Git](src/Upgrade/Git.php), [Composer](src/Upgrade/Composer.php).

### U15 — P2: procesní vrstva nesplňuje příslib ukončení celého stromu

**Rozbor.** `ProcessRunner` na Unixu volá `proc_terminate()` na shell, přestože vlastní komentář správně uvádí, že tím nemusí skončit jeho děti. Na Windows používá `taskkill /T`; chování není sjednocené. Argumentový seznam vždy převádí do shellového stringu, místo aby pro Composer a Git využil přímé spuštění. Výstup obou streamů slučuje bez hranic a bez limitu v paměti. Timeouty 30 a 60 minut nejsou uživatelsky nastavitelné.

`ProcessProbeRunner` nemá deadline vůbec. Při úklidu procesy terminates, ale nemá dokončený protokol ukončení a vyčkání všech potomků před mazáním jejich souborů. `Scope::runGit()` také může čekat neomezeně.

**Změna:** sdílený process executor: argumentový seznam pro přímé programy, shell jen pro záměrně shellový test command, samostatné streamy, omezený/spoolovaný log, timeout, zrušení, ukončení stromu a dokončený cleanup. Otestovat obě OS větve. Nezaměňovat nedostupný příkaz, timeout a záporný/neurčený exit code za běžnou odpověď.

Zdroj: [ProcessRunner](src/Upgrade/ProcessRunner.php), [ProcessProbeRunner](src/Measuring/ProcessProbeRunner.php), [Scope::runGit()](src/Measuring/Scope.php#L401).

### U16 — P1: `upgrade --today --format json` nemá zaručeně jediný JSON na stdout

**Rozbor.** Instalace chybějících dat volá `passthru()` bez přesměrování Composer výstupu. Následně příkaz restartuje dalším `passthru()` s procesním `$_SERVER['argv']`. Composer text se tak může dostat před JSON, a restart nemusí odpovídat argumentům předaným do embedovaného `Application::run()`. Obchází se předané streamy, stdin, pracovní adresář a způsob zachytávání výstupu. Instalační větev navíc na rozdíl od `Upgrade\Composer` nepoužívá `COMPOSER_BINARY` ani společný timeout.

**Změna:** instalace a restart mají být explicitní výsledek orchestrace. Veškeré logy externích programů na stderr nebo do strukturovaného logu; stdout ve strojovém režimu obsahuje právě jeden dokument. Pokračování předávat skutečným requestem, ne globálními argv procesu.

Zdroj: [UpgradeCommand::upgradeToday()](src/Console/Commands/UpgradeCommand.php#L456), [installDataPackages()](src/Console/Commands/UpgradeCommand.php#L487).

### U17 — P2: interaktivita a validace options závisí na vedlejších okolnostech

**Rozbor.** `findMisuse()` řeší rozsáhlou tabulku kombinací flags, ale některé přijaté options se v daném režimu nevyužijí: `--paths` při pokračování a tasks, `--jobs` při vlastním provádění plánu, `--offline` při pokračování. Help přitom může naznačovat jejich širší účinek.

`decideTypes()` a `decideLibrary()` se řídí terminálem, nikoliv formátem `agent`/`json`; i strojový výstup může v interaktivním terminálu vyvolat otázku. `--yes` řeší Composer souhlas, ale není jednotným „neptej se“. Stejný příkaz tak může podle TTY vytvořit jiný plán. Seznam voleb ukazuje téměř celý vnitřní stavový automat uživateli najednou.

**Změna:** rozdělit operace do subcommands a pro každý mít vlastní options a validovaný request. Jednoznačně definovat interaktivní politiku; strojový režim nesmí promptovat a musí uvést zvolené defaulty. U každé option buď realizovat účinek, nebo ji odmítnout.

Zdroj: [UpgradeOptions::findMisuse()](src/Console/Commands/UpgradeOptions.php#L126), [decideTypes()](src/Console/Commands/UpgradeCommand.php#L380), [decideLibrary()](src/Console/Commands/UpgradeCommand.php#L360).

### U18 — P2: `--tasks` není spolehlivý stav celého plánu

**Rozbor.** `findTasks()` spouští běžný check a sbírá jen nefixovatelné/refused violations. Automaticky opravitelná, ale dosud neprovedená práce se neukáže. Jump se kontroluje proti současným verzím, takže před Composer krokem může působit jako prázdný. Opakované téma přepíše dřívější výsledek. `findPostponed()` hledá regexem v celém textu, tedy i ve stringu s ukázkou komentáře. Exit code se počítá jen z tasks, nikoliv z postponed.

**Změna:** odlišit „zbývající manuální zásahy“ od „stav plánu“. Stav má pro každý krok ukázat `pending`, `blocked`, `applied`, `verified`, `skipped` a důvod. Suppressions získávat z parseru a sdíleného modelu, s explicitní politikou toho, zda důvod ovlivňuje dokončení.

Zdroj: [PlanRunner::findTasks()](src/Upgrade/PlanRunner.php#L172), [findPostponed()](src/Upgrade/PlanRunner.php#L193), [UpgradeCommand::listTasks()](src/Console/Commands/UpgradeCommand.php).

### U19 — P2: „data znají cílovou verzi“ ve skutečnosti znamená jen pokrytí její major řady

**Rozbor.** `Move::isKnown()` pro verze od 1.0 porovnává známou verzi pouze s `major.0`. Při datech do `4.1` tedy odpoví true i pro přechod na `4.9`. Název metody, její dokumentace a JSON pole `data` mohou působit jako ověření celé cílové verze. Vedle toho report tiskne konkrétní „up to 4.1“.

**Změna:** rozlišit existenci dat pro major řadu od ověřeného rozsahu dat. Pokud katalog zná jen poslední sekci změn a nikoliv verzi, vůči které byla data skutečně ověřena, musí tuto nejistotu umět vyjádřit. Nenahrazovat chybějící informaci silnějším booleanem. Doplnit fixture pro cíl v novější minor řadě téhož majoru.

Zdroj: [Move::isKnown()](src/Upgrade/Move.php), [EstimatePrinter](src/Console/EstimatePrinter.php).

## 2. `init`: správnost závěrů a kvalita onboardingu

### I01 — P1: opakované `init` může nepravdivě říct, že se nic nezměnilo

**Reprodukce:** kód je napsán čtyřmi mezerami, `dresscode.neon` má `use: perCs` a explicitně `indentation.unit: tab`. `init --use perCs --yes` vypíše:

```text
Measured   your code departs from it in no decision over 1 file
Moved      nothing: `dresscode.neon` says what init measures
```

Vůči standardu není residue, a proto v `Proposal::$values` není nic k porovnání. `printShifts()` neporovnává všechny relevantní hodnoty původní konfigurace s výsledkem měření. Skutečnost, že standard souhlasí s kódem, nevypovídá o explicitních odchylkách konfigurace.

**Změna:** oddělit měření proti standardu od porovnání účinné konfigurace. Porovnávat celou relevantní množinu rozhodnutí, rozsah a overrides. Nezměřené hodnoty označit jako nezměřené; nikdy z prázdného seznamu změn odvodit úplnou shodu.

Zdroj: [Proposal::measureResidue()](src/Measuring/Proposal.php), [InitPrinter::printShifts()](src/Console/InitPrinter.php#L217).

### I02 — P1: „check passes already“ a odhad první opravy se neopírají o přesně tentýž běh

**Rozbor.** `toDraft()` zapíná PHPStan podle balíčků projektu. `buildProbeConfig()` jej záměrně odstraní, aby měření nebylo drahé. `checkWritten()` ověřuje serializaci a vybrané hodnoty resolveru, nikoliv běh pravidel v přesně zapsané konfiguraci. Typy mohou změnit aktivní pravidla, fixovatelnost i počet zbývajících nálezů.

Navíc přítomnost PHPStan v locku není totéž co jeho dostupnost vedle právě běžící instalace DressCode. Projekt může mít lock s dev balíčky a instalaci bez nich. Uživatel přesto dostane sebejistý příslib podle zjednodušené sondy.

**Změna:** měřit levně, ale finální verifikaci provést přesně se zapsanou konfigurací a skutečně dostupnými schopnostmi. Pokud se finální plný běh neprovede, výslovně uvést omezení a nepředpovídat úspěšný `check` jako fakt.

Zdroj: [Proposal::toDraft()](src/Measuring/Proposal.php), [buildProbeConfig()](src/Measuring/Proposal.php#L280), [checkWritten()](src/Measuring/Proposal.php#L292), [InitPrinter::printNext()](src/Console/InitPrinter.php#L149).

### I03 — P1: selhané sondy mohou přispět k příliš optimistickému výběru standardu

**Rozbor.** `ProbeResult` sice zaznamená failed files a některá měření je vynechají, ale výběr standardu stojí na počtu residue rozhodnutí. Standard, jehož pravidlo selhalo, proto nemusí dostat adekvátní penalizaci nebo status neúplného výsledku. `Verification` failed seznam nezahrne do veřejného závěru a `printVerification()` jej nevypíše.

`ProcessProbeRunner::readResult()` navíc přijímá libovolné dekódované pole; report bez očekávaných polí může v `fromJson()` vypadat jako běh bez souborů. Neověřuje se, zda odpověď opravdu pokrývá požadovaný počet a identitu souborů.

**Změna:** platnost měření je předpoklad statistického výběru. Chyby nesmí zlepšit skóre. V odpovědi sondy ověřovat schéma, identitu requestu a pokrytí; neúplný výsledek buď odmítnout, nebo explicitně omezit závěr.

Zdroj: [StandardChoice::make()](src/Measuring/StandardChoice.php), [ProbeResult::fromJson()](src/Measuring/ProbeResult.php), [Verification](src/Measuring/Verification.php), [ProcessProbeRunner::readResult()](src/Measuring/ProcessProbeRunner.php#L126).

### I04 — P1: automatické `nameResolution: certain` má slabší podklady, než tvrdí

**Reprodukce části problému:** při odhadu omezeném na `src/Code.php` není načtena vlastní `App\curl_close()` ze sousedního `src/helpers.php`. Volání v `Code.php` se přesto vyhodnotí jako automaticky opravitelné globální `curl_close()` a report říká `namesMeasured: true`. Samotný plný runner může díky širšímu sběru deklarací rozhodnout jinak — i to potvrzuje rozchod odhadu a provedení.

Obecně jsou seznamy sestaveny jen z vybraného scope, ale deklarace se mohou načítat z `autoload.files`, ignorovaného bootstrapu, vyloučených souborů nebo dalšího balíčku. `NamespaceDeclarations::collect()` nečitelný soubor přeskočí, aniž jej přidá do `unparsed`; ani tehdy tedy jistota nemusí být zpochybněna.

**Změna:** oddělit rozsah úprav od rozsahu deklarací. Do sběru zahrnout soubory, které projekt načítá, a mít model úplnosti. Nečitelný soubor je neúplné zjištění. Samotné „v kontrolovaných souborech jsem nic nenašel“ neopravňuje obecnou jistotu o runtime namespace.

Zdroj: [Estimator::estimate()](src/Upgrade/Estimator.php#L85), [NamespaceDeclarations::collect()](src/Measuring/NamespaceDeclarations.php#L39), [Proposal::toDraft()](src/Measuring/Proposal.php), [ComposerJson::detectAutoloadPaths()](src/Config/ComposerJson.php).

### I05 — P2: budget není horní mez počtu přepsaných souborů

**Rozbor.** `Budget::findDropped()` z rozpočtu odstraní chráněná i uživatelem zodpovězená rozhodnutí. Help však začíná „The files the first fix may change“ a text návrhu mluví o tom, že přijetí konfigurace projekt nepřepíše. Indentace přitom může zasáhnout téměř všechny soubory. Rozpočet nelze z CLI nastavit na 0, protože se parsuje normalizerem pro počet workerů.

Ochrana závislostí měření je technicky pochopitelná. Nemá se ale skrývat ve významu obecného slova budget.

**Změna:** rozhodnout, zda jde o tvrdý celkový limit, nebo limit dodatečných změn mimo explicitně přijaté zásahy. Druhou variantu pojmenovat a vypsat rozpad: například „12 files total; 10 indentation changes accepted separately; limit 2 for other changes“. Podporovat nulový limit a předem ukázat všechny výjimky.

Zdroj: [Budget](src/Measuring/Budget.php), [InitCommand::$budget](src/Console/Commands/InitCommand.php), [InitPrinter::printVerification()](src/Console/InitPrinter.php).

### I06 — P2: heuristika scope se tváří jako popis celého projektu

**Rozbor.** Bez git se nezahrnují jednotlivé soubory z `autoload.files` a file entries classmap. Pokud existuje například `src`, další root entry point může zůstat stranou. S git se měří tracked soubory, ale zapsaná cesta `src` při pozdějším checku zahrne i untracked soubory pod ní. Názvy `fixtures`, `Fixtures` a `expected` se globálně vyřazují i mimo kontext testů.

Zjišťování `@generated` a „do not edit“ stojí na regexu nad textem hlavičky; stejná věta ve stringu nebo citovaném komentáři nemusí být dokladem generátoru. Také 100 kB není důkaz, že soubor není ručně psaný. Statistika vzorku a seznam všech cest jsou dvě odlišné informace.

**Změna:** výsledek detekce scope musí mít původ a míru jistoty. Nabídnout snadný přehled zahrnutých a vyřazených souborů a možnost upravit ho před měřením. Výslovně ukázat rozdíl mezi „měřeno nyní“ a „bude kontrolováno konfigurací“. Heuristické vyloučení nemá bez vysvětlení převážit nad explicitním vstupem uživatele.

Zdroj: [Scope](src/Measuring/Scope.php), [FileSample](src/Measuring/FileSample.php), [ComposerJson::detectAutoloadPaths()](src/Config/ComposerJson.php).

### I07 — P2: prázdný nebo nereprezentativní vzorek nemá dost silný vlastní stav

**Rozbor.** Všechny soubory mohou být příliš velké pro vzorek; přesto zůstává potřeba zvolit standard a vypsat „nearest“. Pravidelné vybírání každého k-tého souboru je opakovatelné, ale nikoliv záruka reprezentativnosti. Byte limit dále preferuje dřívější části seřazeného seznamu. Jedna společná statistika může smíchat dva konzistentní styly v různých adresářích a navrhnout globální toleranci místo přirozených overrides.

Počet porušených rozhodnutí také dává stejnou váhu drobnému rozdílu a rozhodnutí, které mění celý projekt. Je to obhajitelná heuristika, nikoliv objektivní měření vzdálenosti standardů.

**Změna:** zvláštní stav `insufficient evidence`; nevybírat nejbližší standard z nulového důkazu. Vzorek rozložit po adresářích a velikostech, uvést pokrytí v souborech i bytech. U výběru standardu ukázat dva až tři hlavní rozdíly a předpokládaný zásah. Později lze nabídnout detekci odlišných stylových oblastí.

Zdroj: [FileSample::pick()](src/Measuring/FileSample.php), [StandardChoice::choose()](src/Measuring/StandardChoice.php), [MeasurementPlan](src/Measuring/MeasurementPlan.php).

### I08 — P2: doporučená odpověď může vytvořit širokou výjimku a odpovědi si mohou přepsat výsledek

**Rozbor.** `findUnfixable()` z několika nevyhovujících jmen odvodí prefix/suffix a doporučí přidat regex do globálního `naming.except`. Tím se přijímá politika i pro budoucí jména a jiné oblasti, nikoliv jen popis aktuálního stylu. `--yes`, agent a neinteraktivní běh tuto politiku přijmou automaticky.

Každá otázka navíc sestavuje novou hodnotu stejného seznamu `naming.except` z téhož původního návrhu. Pokud jeden round obsahuje více takových otázek, `Proposal::apply()` je zapisuje postupně a poslední může přepsat výjimku přijatou předchozí odpovědí. Chybí detekce konfliktů odpovědí, které zapisují stejnou cestu.

**Změna:** doporučit konzervativní ponechání nálezů k přezkoumání nebo baseline; globální regex výslovně ukázat jako budoucí politiku. Odpovědi aplikovat jako atomickou sadu se slučováním podle domény a detekcí konfliktů. Uvádět rozsah účinku každé výjimky.

Zdroj: [Questions::findUnfixable()](src/Measuring/Questions.php#L194), [createUnfixable()](src/Measuring/Questions.php#L230), [Proposal::apply()](src/Measuring/Proposal.php).

### I09 — P2: kontrola existující konfigurace používá jiná pravidla výběru než běžné CLI

**Rozbor.** `init` používá `Loader::listFiles()` a první výsledek. Pořadí může upřednostnit `dresscode.neon.dist` před lokálním `dresscode.php`, zatímco `Loader::find()` dává přednost lokální konfiguraci a nejednoznačnost odmítá. `init` také hledá jen v aktuálním adresáři; běžný check hledá vzhůru. Existující neplatná konfigurace se zachytí a její konkrétní chyba zmizí v obecném textu „no configuration init can compare with“, s úspěšným exit code.

Komentář a dokumentace říkají, že se nad existující konfigurací nebere žádná odpověď. Smyčka přes `--set` ale proběhne před touto větví, takže CLI odpověď ovlivnit návrh a srovnání může.

**Změna:** společné rozlišení projektu a aktivní konfigurace. Nad existující konfigurací použít samostatnou operaci porovnání, jasný exit status a původní diagnostiku. `--set` buď podporovat jako explicitní scénář, nebo zde odmítnout; neignorovat ho jen v dokumentaci.

Zdroj: [InitCommand::init()](src/Console/Commands/InitCommand.php#L42), [Loader::find()](src/Config/Loader.php), [Loader::listFiles()](src/Config/Loader.php).

### I10 — P2: chybí levný preview a validace před drahým měřením

**Rozbor.** `init` má jeden hlavní účinek: zapsat soubor. Nemá `--dry-run`, JSON návrh nebo volbu output. Hodnoty `--set` se dekódují a validují až po měření. Uživatel může čekat na mnoho sond a teprve pak zjistit chybu NEON nebo neexistující rozhodnutí. Není samostatná volba počtu procesů ani přiměřený limit paralelismu s ohledem na paměť.

Kontrola „soubor neexistoval“ se provede na začátku. Zápis na konci nemá exkluzivní create a může přepsat soubor vytvořený během měření. Stejná konstrukce je v migraci konfigurace.

**Změna:** nejprve zvalidovat celý request a zjistit scope. Umožnit náhled konfigurace, diff a strukturované výsledky; samotné uložení je samostatný závěrečný účinek. Výsledný soubor vytvářet atomicky s ochranou proti přepsání. Paralelismus řídit explicitně, nejen počtem CPU.

Zdroj: [InitCommand::init()](src/Console/Commands/InitCommand.php#L42), [ProcessProbeRunner::run()](src/Measuring/ProcessProbeRunner.php#L54), [MigrateCommand::config()](src/Console/Commands/MigrateCommand.php#L38).

## 3. `migrate`: zachování významu a rozsahu

### M01 — P1: `--output` do jiného adresáře změní význam relativních cest

**Reprodukce:** migrace PHPCS s `<file>src</file>` do `--output config/dresscode.neon` vrátí 0. Načtení vzniklé konfigurace přes `Loader::load()` dá kořen končící `/config`, `paths: [src]` a neexistující cestu `/config/src`.

Scope je vytvořen vůči working directory, ale `Draft` zapisuje tyto relativní cesty beze změny do libovolného `$target`. Loader při běžném použití bere kořen z adresáře konfigurace. Stejný problém se týká excludes a overrides; `--root` sice dovolí některé běhy obejít, soubor to však sám nevyjadřuje.

**Změna:** před zápisem určit výsledný kořen a rebazovat všechny relativní cesty, nebo zakázat jiný kořen bez explicitní volby a vysvětlení. Finální výsledek načíst stejným loaderem jako běžný `check` a ověřit identitu souborů.

Zdroj: [MigrateCommand::config()](src/Console/Commands/MigrateCommand.php#L38), [Draft::setScope()](src/Config/Draft.php), [Loader::load()](src/Config/Loader.php).

### M02 — P1: explicitní scope cizího nástroje se může tiše zahodit

**Reprodukce:** `<file>.</file>` v PHPCS konfiguraci projektu s adresářem `src` vede bez vlastního git repozitáře k `Scope::listPaths() === ['src']`. Kořen byl přeložen na prázdné pole, které dále znamená „formatter cesty neurčil“. Root soubory tím mohou vypadnout. Absolutní `<file>W:/.../src</file>` se také ztratí, protože `resolvePaths()` před něj vždy přidá adresář konfigurace.

Chybějící nebo vnější cesta se tiše zahodí. Pokud byly takto zahozeny všechny, následuje heuristický fallback. Uživatel tak místo chyby překladu dostane jiný rozsah projektu.

**Změna:** rozlišit `scope unknown`, explicitní kořen, explicitní prázdný scope a konkrétní seznam cest. Používat skutečné řešení absolutních/relativních cest. Nerozumění explicitnímu omezení nesmí být důvodem k tichému rozšíření nebo zúžení.

Zdroj: [FormatterConfig::resolvePaths()](src/Interop/FormatterConfig.php#L207), [Scope::fromProject()](src/Measuring/Scope.php#L114).

### M03 — P1: scope PHP CS Fixer se odhaduje regexy a po spuštění konfigurace se nezpřesní

**Rozbor.** Čtou se jen některé textové podoby `->in(__DIR__ . '/...')` a `->exclude('...')`. Pole cest, proměnné, helpery, `notPath()`, vlastní `name()`/extensions nebo podmínky nemají úplný model. Regex může navíc odpovídat zakomentovanému kódu či ukázce. U konfigurace v podadresáři jsou cesty vstupu přepočítány, ale regexy excludes vznikají z necelých textových relativních cest a mohou mít jinou základnu.

Po souhlasu s execution `translate()` převezme rules, indent a line ending. Skutečný Finder už ke zpřesnění scope nepoužije. Tedy ani vykonaná konfigurace neznamená přesně migrovaný rozsah.

**Změna:** textovou inspekci použít jako explicitně neúplný předběžný odhad. Z vykonané konfigurace získat skutečný seznam souborů a dostupná omezení. Nejde-li rozsah zapsat ekvivalentně, vypsat rozdíl a vyžadovat rozhodnutí o něm před aplikací. Pro statickou podporu PHP využít parser místo regexů.

Zdroj: [FormatterConfig::readPhpCsFixer()](src/Interop/FormatterConfig.php#L168), [translate()](src/Interop/FormatterConfig.php#L257), [PhpCsFixer::readConfig()](src/Interop/PhpCsFixer.php#L898).

### M04 — P2: detekce formatteru nesplňuje deklarované „ten, který spouští projekt“

**Reprodukce:** Composer script spouští `vendor/bin/pint`, v rootu jsou `pint.json` a starší `phpcs.xml`. `FormatterConfig::find()` zvolí PHP_CodeSniffer.

První průchod skripty řeší jen určité explicitní `--standard`/`--config` podoby. Jinak rozhoduje pořadí enumu a jmen souborů, teprve potom se hledá související script. Pro PHP CS Fixer je `.dist.php` před lokální `.php`. Regexy nezpracují obecně citované cesty, `--config` Pint ani Composer aliasy. Dva aktivní nástroje nelze popsat jedním automatickým vítězem.

**Změna:** detekce má vracet kandidáty s důkazem, způsobem spuštění a konflikty. Explicitní vstup má přednost; při nejednoznačnosti nabídnout výběr nebo deterministickou chybu. Precedenci lokální/dist konfigurace ověřit fixturemi a stejnou politiku používat v `init` i `migrate`.

Zdroj: [FormatterConfig::find()](src/Interop/FormatterConfig.php#L57), [Formatter::getRootFiles()](src/Interop/enums.php).

### M05 — P1: neznámý preset a nepodporované části konfigurace mohou zmizet bez varování

**Reprodukce:** Pint konfigurace s `preset: typo` se úspěšně přeloží do souboru bez presetu. Výstup je „Read 0 rules; set 0 decisions and 0 presets“, bez diagnostiky neznámého presetu. Týž vstup může nést nepřenesené filtry, o kterých se také nic neřekne.

Pint reader nezachycuje všechny položky ovlivňující scope a nevaliduje celé schéma. PHPCS reader také nevede seznam všech přečtených a nevyužitých prvků: například nastavení extensions nebo externě skládaný ruleset nemají obecný průkaz zachování. Samotná syntaktická platnost XML není důkaz správného root elementu rulesetu. U překladových closures zase neexistuje obecná kontrola, že spotřebovaly všechny zadané options; ta pro jednoduché tabulkové překlady existuje jen v hrubší podobě.

**Změna:** povinná evidence spotřebovaných vstupů. Neznámý preset je chyba. Každé nepřenesené pole nebo option musí být označeno jako ignorované, aproximované nebo blokující. Speciálně rozlišit legitimní prázdný preset od neznámého. Přidat strukturovaný migrační report s původem každého rozhodnutí.

Zdroj: [FormatterConfig::readPint()](src/Interop/FormatterConfig.php#L103), [translate()](src/Interop/FormatterConfig.php#L257), [PhpCodeSniffer::readConfig()](src/Interop/PhpCodeSniffer.php#L811), [Translator::translate()](src/Interop/Translator.php).

### M06 — P2: migrovaný soubor se zapisuje před ověřením jako celek

**Rozbor.** `migrate config` nepoužije obdobu `Proposal::checkWritten()`: načtení výsledného souboru, resolver a ověření účinných cest. Testy překladových řádků ověřují důležitou část hodnot, nikoliv libovolnou kombinaci skutečného vstupu, overrides a cílového adresáře.

Warnings se vypisují po zápisu; i velmi částečný překlad skončí kódem 0. Není přepínač vyžadující úplný překlad, JSON nebo náhled výsledku. Nový soubor neuchová systematicky původní rule a důvod aproximace, přestože `Draft` komentáře umí. Při pozdějším review se tato informace ztratí v konzoli.

**Změna:** sestavit `MigrationResult`, zvalidovat jej, vytisknout souhrn úplnosti a až potom atomicky zapsat. Nabídnout `--dry-run`, strojový report a režim, který nepovolí ztrátu pravidel/rozsahu. Uchovat provenance ve výsledku nebo v přidruženém reportu, nikoliv ji znovu odvozovat z textů warnings.

Zdroj: [MigrateCommand::config()](src/Console/Commands/MigrateCommand.php#L38), [Translation::toDraft()](src/Interop/Translation.php), [Draft](src/Config/Draft.php).

### M07 — P1: execution PHP konfigurace není izolována od vlastního CLI

**Rozbor.** Souhlas při automatické detekci a explicitní pojmenování souboru jsou rozumná současná pravidla. Po souhlasu se však soubor `require` spustí přímo v procesu DressCode. Může napsat vlastní výstup, změnit cwd či globální stav, ukončit proces nebo se zaseknout. `readConfig()` zachytává `Error`, nikoliv libovolné selhání cizího kódu. Po změně cwd se může skutečný běh sond nebo překlad lišit od původně zamýšleného kontextu.

**Změna:** vykonání cizí konfigurace delegovat do samostatného procesu s pracovním adresářem projektu, timeoutem a jasným protokolem. Zachytit její výstupy a vrátit data či diagnostiku. To je ochrana integrity nástroje a jeho reportu, nikoliv tvrzení, že samostatný proces je bezpečnostní sandbox.

Zdroj: [PhpCsFixer::readConfig()](src/Interop/PhpCsFixer.php#L898), [InitCommand::readFormatterRules()](src/Console/Commands/InitCommand.php#L192), [MigrateCommand::findConfig()](src/Console/Commands/MigrateCommand.php#L111).

### M08 — P2: migrace suppressions mění soubory bez preview a při přeskočení chyby vrátí úspěch

**Reprodukce:** syntakticky neplatný `src/Code.php` se vypíše jako `skipped`, následně „Migrated 0 suppression comments in 0 files“, exit code **0**.

Neexistuje `--dry-run`, diff nebo formát JSON. Chybí souhrn zkontrolovaných, přeskočených a chybných souborů. Před zápisem se neověří, že soubor od načtení nezměnil uživatel, na rozdíl od `Runner::processPath()`. Při chybě uprostřed zůstanou předchozí soubory migrované bez strukturovaného výsledku částečného běhu.

**Změna:** migraci modelovat jako patch set nad původními hashi. Umožnit review a atomické zápisy jednotlivých souborů s detekcí souběžné změny. Neúplný běh musí mít odpovídající exit code a seznam souborů. Transakci celého projektu není nutné přidávat, pokud produkt jasně reportuje částečné provedení a umí pokračovat.

Zdroj: [MigrateCommand::suppressions()](src/Console/Commands/MigrateCommand.php#L142).

### M09 — P2: migrátor a interpret suppressions mají rozdílnou gramatiku

**Reprodukce:** komentář `// prose phpcs:ignoreFile is an example` se změní na `// prose dresscode:ignoreFile is an example` a `is` je vykázáno jako neznámé jméno. Migrátor tedy upravuje i prózu, nikoliv jen normalizovanou direktivu.

Regex v `rewrite()` nemá stejnou hranici a ukončení jako parser `Suppression`. Jméno anotace `@phpcsSuppress` se zpracovává odlišným vzorem a přepisuje první zachycené jméno, zatímco interpret zná i seznam. `countSuppressions()` zase používá třetí, čistě textový odhad nad celým souborem, takže může započítat ukázky uvnitř stringů. Neznámá jména se při změně prefixu zachovají, ale výsledná DressCode direktiva může hlásit nový problém a uživatel nedostane přesné umístění každého takového případu.

**Změna:** jedna gramatika direktiv a jeden strukturovaný model, sdílený mezi interpretací, počítáním, migrací a tasks. Přepisovat jen rozpoznané rozsahy a zachovat okolní text. Uvádět soubor, řádek, původní jméno, nové rozhodnutí a případnou změnu významu suppressions.

Zdroj: [SuppressionMigration::rewrite()](src/Engine/SuppressionMigration.php#L66), [Suppression](src/Engine/Suppression.php), [PhpCodeSniffer::countSuppressions()](src/Interop/PhpCodeSniffer.php).

## 4. Audit textů a uživatelského zážitku

### T01 — P2: příkazy nesdílejí srozumitelný kontrakt účinku

`init` zapisuje konfiguraci, nad existující konfigurací se ale mění v měřicí audit. `upgrade` bez flags odhaduje, s `--today` opravuje, s dalšími flags plánuje, ukládá, provádí nebo kontroluje úkoly. `migrate config` zapisuje jiný formát nastavení, `migrate suppressions` přepisuje zdroje. To jsou odlišné operace s odlišným výsledkem a chováním při neúplnosti.

Není potřeba vymýšlet mnoho synonym; je potřeba v prvních řádcích každého běhu říct **co se bude číst, co se může změnit, kde výsledek skončí a jak se ověří**. Změna chování podle existence souboru nebo TTY musí být v těchto řádcích patrná.

### T02 — P2: texty mají čitelné jednotlivosti, ale příliš dlouhé věty a nepřesné přísliby

Konkrétní doporučené přepisy:

| Současný text / místo | Problém | Navrhovaný směr textu |
| --- | --- | --- |
| `Show what ... costs` | Počítají se místa a soubory, ne čas nebo pracnost lidské práce. | `Estimate the code changes needed for ...` |
| `Must change` nad `deprecations` a `cleanup` | Ne každý nález je nutnou podmínkou běhu na cíli; deprecation nemusí být odstraněné API. | Oddělit `Compatibility`, `Deprecations`, `Cleanup`, `Optional modernization`. |
| `after the raise`, `works today` | Pojmy vyžadují znalost interního modelu; „today“ může znít jako instalovaná verze. | `Works on PHP >=8.0` / `Requires PHP >=8.4`, s konkrétními verzemi. |
| `for you`, `places` | U velkého výstupu je málo patrné, co je konkrétní akce a proč není automatická. | `manual changes`, `occurrences`, doplnit důvod a první příklad. |
| `UPGRADED every step ... done` | Neodpovídá U02, U06, U07; neříká nic o testech a pokrytí dat. | Výsledek podle ověřených podmínek, např. `Applied 2 steps; 1 manual task remains; tests not run.` |
| `The packages are at the lowest versions ...` | Odvozeno z existence update kroku, nikoliv z doloženého výsledku instalace. | Uvést skutečně ověřený stav nebo jen příkaz, který byl proveden. |
| `Nothing to upgrade` | Prázdný seznam nálezů není důkaz úplné kompatibility, zvlášť při chybách nebo chybějících datech. | `No changes detected by the available rules`, s přehledem pokrytí. |
| `nameResolution ... resolved for certain` | Příliš silné vzhledem ke scope deklarací. | Popsat zdroj a úplnost seznamů; nejistotu převést do modelu, ne jen slovního disclaimeru. |
| `a fix changes ...` / `check passes already` | Finální konfigurace se může chovat jinak než sondy. | Až po plném finálním ověření; jinak `estimated` a přesný důvod. |
| `it is no configuration init can compare with` | Nepřirozená angličtina, ztrácí konkrétní chybu. | `The existing configuration could not be loaded: ... No file was written.` |
| `a step goes with skip: true` v hlavičce plánu | Nejasné, zda skip krok zapne, nebo vypne. | `Set skip: true to skip a step.` |
| `1 PHP files`, `all 1 files` v `init` | Skutečně pozorovaná gramatická chyba. | Jeden společný formatter počtů a přirozená věta pro nulu/jednotné číslo. |

Help `--today` a některých dalších options obsahuje několik vedlejších vět a interní pojmy jako „sets“, „lowest versions“, „types beside DressCode“. Krátký help má popsat účinek; podrobnosti patří do navazujícího vysvětlení a příkladů. Je vhodné ukázat tři běžné příklady už v helpu upgradu.

### T03 — P2: informace a návazné příkazy nejsou vždy použitelné

`EstimatePrinter` dobře ukazuje rozsah dat, ale `PlanPrinter` nemá stejně dobrý kontext a po `--plan` uživatele systematicky nenavede k uložení/spuštění. Task výstup nezobrazuje plnou strukturu rizika (`risk`, `because`, fixovatelnost), přestože právě důvod rozhoduje, co má člověk udělat. Ruční Composer příkaz a doporučení baseline potřebují důvod a dopad, ne jen mechanický další krok.

`InitPrinter::formatValue()` navíc není renderer domény: používá cast nebo `implode()` bez klíčů. Pro mapu zadanou přes `--set` může být zobrazení zavádějící, u vnořených hodnot i chybové. V projektu už existuje `Value::toWrittenText()`, které je vhodnějším zdrojem pravdy.

**Změna:** každý výsledek ukončit konkrétním navazujícím příkazem, který zachová scope a zvolené parametry. U lidského úkolu zobrazit příklad a důvod; ve strojovém výstupu zachovat všechna relevantní pole violation. Hodnoty všude formátovat podle domény.

### T04 — P2: exit codes a streamy nejsou sjednocený veřejný kontrakt

Globální help vysvětluje 3 jako chybu příkazové řádky/konfigurace, ale upgrade stejným kódem oznamuje i očekávané čekání na Composer. Migrace může přeskočit chybné soubory a vrátit 0. Odmítnutí migrace PHP konfigurace také vrací 0, přestože se nic nevytvořilo. Tyto situace není nutné všechny nazývat chybami; automatizace je ale musí umět rozlišit.

`migrate config` používá pro lidský výsledek stderr, suppressions převážně stdout; upgrade má další vlastní JSON writer. Věk a původ cache katalogu i skutečnost, že síťové zjištění selhalo, nejsou v odhadu dostatečně strukturované. „Nemáme data“ a „nepodařilo se zjistit, zda data existují“ jsou různé stavy.

**Změna:** zveřejnit tabulku exit statusů pro celou CLI a uvnitř JSON povinné `status`, `complete`, `changed`, `verification` a diagnostics. Lidské/progress/external logy oddělit od dat. Katalog vrací záznam o zdroji, stáří a úspěchu aktualizace, nikoliv jen prázdné pole.

Zdroj: [Application](src/Console/Application.php), [MigrateCommand](src/Console/Commands/MigrateCommand.php), [DataPackageCatalogue](src/Config/DataPackageCatalogue.php), [RunOutcomePrinter](src/Console/RunOutcomePrinter.php).

## 5. Návrh kódu, rozhraní a názvy

### Co bych zachoval

- `ProbeRunner` je užitečná skutečná hranice: stejná sémantika sond, více implementací. Potřebuje přísnější výsledek, nikoliv zrušení.
- `Draft` odděluje podobu konfiguračního souboru od aplikační logiky. Má smysl doplnit validaci a provenance.
- `Target`, `Topic`, `Task`, `Plan`, `RunOutcome` jsou rozumné doménové pojmy. Problém je především neúplnost jejich kontraktů.
- `Translation` má propracovanější slučování než obyčejné přepisování pole a rozlišuje pořadí, tolerance a pevnost rozhodnutí. Toto chování zachovat a testovat na kombinacích.
- Rozlišení standardu, sets a projektové politiky je dobrý základ. CLI ho má uživateli dávkovat, nemusí ho celé vysvětlovat v každé option.

### A01 — P2: command handlery jsou zároveň aplikační služby a infrastruktura

`UpgradeCommand` vybírá režim, řeší interaktivitu, konstruuje estimator, serializuje worker job, instaluje balíčky, restartuje proces, spouští plán, vybírá formatter i zapisuje JSON. `InitCommand` orchestruje měření, otázky, import cizích pravidel i finální zápis. Nelze snadno testovat selhání Composeru nebo zápisu bez skutečných procesů a filesystemu.

**Doporučení:** tenký handler převádějící CLI na validovaný request; samostatná služba vracející výsledek; renderer a zapisovač provádějící explicitní účinky. Injektovat jen hranice, které jsou opravdu vnější: procesy, filesystemové zápisy/recovery, katalog dat a interaktivní rozhodnutí. Nepřidávat rozhraní pro každý DTO nebo čistou funkci.

### A02 — P2: `Step` a `RunOutcome` dovolují neplatné kombinace stavů

Jeden `Step` obsahuje `kind`, `only`, `require`, `update`, `suggest` a `skip`, ačkoliv většina polí pro konkrétní druh nedává smysl. Jeden `RunOutcome` dovolí `Done` spolu s tasks nebo failures. `Target` přijímá libovolný string; CLI validuje, ale čtení plánu ne.

**Doporučení:** typově odlišit `FixStep`, `ComposerStep` a blokující `ManualStep`, případně přísně validovaný uzavřený model se společným kontraktem. Výsledek běhu musí invarianty vynucovat při vzniku. `Target` validovat na společné vstupní hranici, aby byl stejný z CLI, plánu i testu.

### A03 — P2: dvě `Project` nejsou samy o sobě chyba, ale společná identita se rozpadá

`Measuring\Project` drží detekované facts a formatter; `Upgrade\Project` konfiguraci a běhové operace. Rozdělení je rozumné. Problém je, že každý příkaz jinak hledá root, scope, balíčky a konfiguraci. Vznikají i operace jako `findKeptDecisions()`, které pod zdánlivě levným `find` spouštějí kontrolu celého projektu.

**Doporučení:** sdílený neměnný popis zjištěného projektu s původem vstupů; odděleně scope měření, scope deklarací a scope zápisu. Výpočet zachovávaných rozhodnutí je explicitní analýza s výsledkem a diagnostikou, nikoliv neviditelný getter.

### A04 — P2: pojmy „keep“, „left“ a „only“ mají příliš mnoho významů

`keep` je uživatelská hodnota vypnutí rozhodnutí, `$kept` jsou ale aktivní požadavky, které se mají dál vynucovat, a callback `$keep` ukládá snapshot. `$only` může být číslo kroku, výběr témat nebo výběr rozhodnutí. `$left` znamená úkoly i jiné zbytky výpočtu. Lokálně se kód číst dá; při sledování celého workflow vzniká zbytečný překlad mezi významy.

Přejmenování má vyjasnit odpovědnost, nikoliv jen prodlužovat všechny názvy:

| Současný název | Doporučení / důvod |
| --- | --- |
| `UpgradeOptions` | Nahradit requests podle operací; tím zmizí většina nullable flags a `findMisuse()`. |
| `TodayUpgrade` | Např. `CurrentVersionUpgrade`: výslovně aktuálně povolené verze. Ještě lépe mu přiřadit jasnou aplikační operaci. |
| `offer()` | `estimateUpgrade()` nebo handler samostatného subcommandu; „offer“ není přesný popis měření. |
| `findConsole()` | `createConsole()` / `resolveOutput()`: metoda konzoli také vytváří, nejen hledá. |
| `Project::findKeptDecisions()` | `measureSatisfiedRequirements()`; název vyjadřuje běh analýzy a neplete se s hodnotou `keep`. |
| `$kept` | `$satisfiedRequirements` nebo `$preservedStyleDecisions`, podle skutečně omezené sémantiky. |
| snapshot callback `$keep` | `$saveOriginal` / `$beforeWrite`; musí být skutečně volán před zápisem. |
| `PlanRunner::forget()` | `removeRunState()`; název má přiznat odstranění recovery dat. |
| `isRaised()` | Rozdělit na `matchesManifestConstraints()` a kontrolu locku/instalace. Současný název slibuje víc, než metoda zjistí. |
| `$raised` | `$afterComposerStep`, dokud znamená průchod krokem včetně již splněného. |
| `Step::$only` | `$topics` či konkrétní `$decisions`; nepřebírat obecný název CLI filtru. |
| `Plan::$dresscode`, `$data` | `$toolVersion`, `$dataPackageVersions`; verze schématu musí být zvlášť. |
| `RunOutcome::$fixed` | `$changes` / `$appliedChanges`, s oddělením vrácených změn. Po rollbacku „fixed“ klame. |
| `Budget::$files`, `$given` | `$maxChangedFiles`, `$configuredLimit`; zvláštní jednotku i výjimky vyjádřit modelem. |
| `FileSample::$oversized` | `$omittedForSize`, případně rozlišit limit souboru a vyčerpaný byte budget. |
| `StandardChoice::make()` | `measure()` nebo `chooseFromProbes()`; obecné make zatajuje nákladný účinek. |
| `Proposal::apply()` | `applyAnswers()`; ve třídě existuje více druhů změn. |
| `Proposal::answer()` | `setAnsweredValue()`; nastavuje jednu hodnotu a její původ. Při zachování setterové odpovědnosti vracet `static` podle konvencí repozitáře. |
| `checkWritten()` | `checkSerializedConfiguration()`; kontroluje zatím nezapsaný výsledek a nikoliv skutečný běh checku. |
| `findUnfixable()` / `createUnfixable()` | `findNamingQuestions()` / `createNamingQuestion()`; aktuální metody neřeší obecnou nefixovatelnost. |
| `InitPrinter::print*()` | Pokud vrací text pro čtenáře, sjednotit na `format*()` podle konvencí repozitáře; vlastní výpis je `write*()`. `render*()` ponechat skládání kódu. |
| `PhpCsFixer::readConfig()` | Rozlišit statické `inspectConfiguration()` a vykonávající `executeConfiguration()`. |
| `ComposerJson::read()` používané pro Pint | Obecný JSON reader se schématem, Composer facts samostatně; doménový název nemá být univerzální tolerantní parser. |

`$args`, `$params`, `$base` nebo `$i` bych automaticky nepřejmenovával. Krátká lokální jména odpovídají konvencím a jsou vhodná tam, kde mají jediný jasný význam. Problémem nejsou krátká jména obecně, ale nejasné přenosy stavů mezi vrstvami.

### A05 — P3: komentáře často vysvětlují dlouhou větou to, co má vyjadřovat kontrakt

V některých třídách je užitečný detail schovaný v několika souvětích u konstruktoru nebo metody. Komentář například slibuje ukončení stromu procesů nebo obnovu před změnou, zatímco implementace záruku nesplňuje. Tím se komentář stává falešnou oporou při dalším vývoji.

**Doporučení:** u hranic uvádět krátké invarianty, předpoklady a výsledek selhání. Vysvětlení algoritmu ponechat u samotného algoritmu. Omezit příběhové formulace „what ... which ... where ...“ tam, kde několik přesných vět popíše kontrakt lépe. Pravidla návratových typů, akcí a pojmenování mají zůstat konzistentní s `AGENTS.md`.

## 6. Cílový návrh workflow bez omezení kompatibilitou

Navrhuji následující rozdělení. Jména subcommands jsou návrh produktu, nikoliv požadavek zavést všechny přesně takto.

```text
dresscode init                         measure and create a configuration
dresscode init --dry-run               show the proposed configuration and scope
dresscode config compare               compare the active configuration with measured code

dresscode upgrade estimate php 8.4     estimate changes and coverage
dresscode upgrade plan php 8.4         create a reviewable plan
dresscode upgrade apply                apply the saved plan
dresscode upgrade status               show remaining work and verification
dresscode upgrade current              fix for currently allowed versions

dresscode migrate config [file]        translate configuration with a completeness report
dresscode migrate suppressions --diff  preview suppression changes
```

`init` bez dotazů má být deterministické. Interaktivní rozhodnutí jsou vrstva nad stejným návrhem; odpovědi se ukládají jako reprodukovatelné vstupy. Existující konfiguraci má explicitně porovnávat s její účinnou podobou. Konfigurace se ukládá až po finálním ověření a proti přepsání chráněným zápisem.

Odhad a plán upgradu mají sdílet přesný scope a konkrétní transformace constraints. Plán ukládá verze, rozsah, rozhodnutí, předpoklady, způsob ověření a stabilní identifikátory kroků. Vedle něj je běhový journal; nemíchá se do něj odvozená statistická cache. Při opakovaném spuštění se stav znovu ověřuje podle kódu, ale zjištěné rozpracované zápisy se nejdříve bezpečně vyřeší. To zachovává dobrou současnou vlastnost nezávislosti na lineární git historii.

Pouhé opakované vyhodnocování proti kódu nestačí jako recovery mechanismus: umí zjistit, co ještě lze opravit, ale neumí samo rozhodnout, zda starý snapshot smí přepsat současnou uživatelovu práci.

Typický krok aplikace:

1. Ověřit request, plán a předpoklady v aktuálním projektu.
2. Spočítat konkrétní změny a jejich původní hashe.
3. Bezpečně uložit originály a záznam připraveného kroku.
4. Aplikovat změny s kontrolou souběžných editací.
5. Zkontrolovat chyby enginu a požadované testy.
6. Zaznamenat ověřený stav; případně commitovat právě tuto ověřenou jednotku.
7. Při selhání provést kontrolovanou obnovu nebo oznámit konfliktní recovery, nikdy automaticky přepsat novější cizí obsah.

Po Composer update navázat čerstvým procesem, který znovu načte autoload, pluginy, metadata a analýzy. Nástroj nemusí umět transakčně vrátit celý vendor, musí ale přesně říct, co už se změnilo a jaké podmínky byly či nebyly ověřeny.

Migrace konfigurace má mít výsledek typu „přeneseno přesně / aproximováno / vynecháno / vyžaduje rozhodnutí“. Nesmí generovat pocit ekvivalence prostým počtem přeložených rules. Výsledek musí zachovat scope a u každé změny politiky ukázat její důvod.

## 7. Co opravit v jakém pořadí

### První priorita: pravdivost a integrita

- Sjednotit scope odhadu a zápisu; opravit rebasing migrované konfigurace a ztrátu explicitního root scope.
- Propagovat chyby souborů a neúplné výsledky sond.
- Zpřísnit testovací bránu a dokončení plánu.
- Ukládat recovery před zápisem a chránit obnovu před přepsáním cizích změn.
- Opravit explicitní vypnutí typů a falešné „nothing moved“.
- Validovat plán přísným schématem a odmítat neexistující krok.

### Druhá priorita: přesná orchestrace

- Oddělit změnu manifestu od aktualizace instalace a kontrolovat obě podle záměru kroku.
- Zachovat konkrétní rozhodnutí, fáze a účel kroku, nikoliv pouze jméno balíčku.
- Sjednotit Composer preview s tím, co se skutečně provede.
- Rozlišit scope deklarací, měření a zápisu; nepřisuzovat úplnost bez důkazu.
- Zavést společnou procesní vrstvu a konzistentní strojový výstup.
- Doplnit migrační report úplnosti a strukturované diagnostiky.

### Třetí priorita: zjednodušení produktu a kódu

- Rozdělit režimy upgradu do samostatných requests/subcommands.
- Doplnit preview, status a srozumitelné návratové stavy.
- Přepsat dlouhé help texty, nepřesné úspěšné přísliby a sjednotit formát hodnot.
- Zpřesnit názvy podle skutečných odpovědností a zkrátit kontraktové komentáře.

## 8. Testy, které chybějí

Současná sada je užitečná a prošla; hlavní mezera je mezi jednotkovou správností částí a pravdivostí celého workflow. Některé testy navíc fixují slabý kontrakt, například neplatný JUnit jako prázdný výsledek. Prioritně bych doplnil následující scénáře:

| Oblast | Scénáře a požadovaná vlastnost |
| --- | --- |
| Scope | `--paths` s `--run` i pokračováním; žádný zápis mimo plán. Migrační output do podadresáře zachová stejnou množinu souborů. |
| Chyby enginu | Parse error, výjimka pravidla, non-convergence, nečitelný soubor a odmítnutý zápis; nikdy `Done` nebo úplný odhad. |
| JUnit | Neplatný/truncated XML, prázdná sada, timeout po zápisu reportu, nenulový exit bez řádného dokončení, zmizelý test, skipped a duplicity. |
| Recovery | Výjimka před/po zápisu, nedostatek místa na snapshot, přerušení procesu, poškozený index, opakovaný běh a uživatelská editace před rollbackem. |
| Composer | Změněný manifest se starým lockem/vendorem, platforma pod cílem, již splněný constraint bez instalace, knihovní `--no-update`, změna závislostí a fresh process. |
| Plán | Typo v klíči, chybný bool, nesprávný typ seznamu, nepovolená kombinace polí, neznámé schemaVersion, neexistující/skipnutý krok. |
| Dokončení | Úkol v bridge, posledním kroku i po Composeru; ruční blokující krok; nulový exit jen podle výslovně ověřeného kontraktu. |
| Typy | CLI on/off/auto × konfigurace on/off × PHPStan dostupný/nedostupný, stejně v procesu i workeru. |
| `init` | Odchylka uložená explicitně, přestože kód souhlasí se standardem; overrides; prázdný vzorek; selhání sond; finální check se zapnutými typy. |
| Odpovědi | Dvě otázky zapisují stejný seznam; žádná přijatá výjimka se nesmí ztratit. Vynucené `--set` musí mít předvídatelnou prioritu. |
| Cizí konfigurace | Root a absolutní paths, subdirectory config, lokální/dist precedence, dva formattery, Composer aliases, dynamický Finder, neznámý Pint preset a nepřečtené scope filters. |
| Suppressions | Příklady v próze/stringu, seznam v anotaci, unknown names, více direktiv, parse skip, souběžná editace a idempotence. |
| Automatizace | JSON je jediný stdout dokument i při instalaci dat a chybě; agent/JSON nikdy nepromptuje; reprodukovatelné requesty při embedovaném `Application`. |
| Git | Untracked původní práce, staged změny, selhávající status/hook, překryv témat, Composer změny a pokračování s `--commit`. |

K překladovým tabulkám bych vedle validačních testů přidal menší sadu srovnávacích fixtures: stejný vstup, konkrétní podporovaná verze cizího nástroje a DressCode, očekávané shody i výslovně přiznané odlišnosti. Nemá smysl tvrdit úplnou ekvivalenci jen proto, že oba výstupy jdou načíst.

Nejvyšší hodnotu teď mají testy nežádoucích stavů a zachování úmyslu uživatele. Další snapshoty formulací výstupu bez ověření jejich pravdivosti by hlavní rizika nezmenšily.
