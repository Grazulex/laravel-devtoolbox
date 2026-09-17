# DevToolbox MCP Server — Design

**Date** : 2026-09-17
**Package** : `grazulex/laravel-devtoolbox` (v1.6.0 → v1.7.0)
**Compatibilité** : Laravel 12 et 13, PHP ≥ 8.3

## 1. Objectif

Exposer les scanners de DevToolbox comme **tools MCP** (Model Context Protocol) via
`laravel/mcp`, pour qu'un agent de code (Claude Code, Cursor, Codex, Gemini CLI…)
puisse interroger l'application en direct : routes non protégées, usage d'un modèle,
requêtes N+1, timeline des providers, etc.

Fournir en plus les fichiers **Laravel Boost** (guidelines + skill) pour que les agents
sachent quand et comment utiliser ces tools.

Hors périmètre v1 : transport HTTP (`Mcp::web`), resources et prompts MCP, tools
composés, `outputSchema` typé par scanner, pagination des résultats, serveurs MCP pour
Atlas et ChronoTrace (chantiers séparés).

## 2. Décisions

| Sujet | Décision |
|---|---|
| Périmètre | Tous les scanners du `ScannerRegistry`, **un tool par scanner**, générés automatiquement |
| Dépendance | `laravel/mcp` en `suggest` (+ `require-dev` pour les tests). Activation conditionnelle : rien ne change pour un utilisateur qui ne l'installe pas |
| Transport | **Local uniquement** (`Mcp::local('devtoolbox', …)` → `php artisan mcp:start devtoolbox`) |
| Environnements | Serveur enregistré uniquement en `local` / `testing` (liste configurable) ; re-vérifié au boot du serveur |
| Scanners actifs | `sql-trace`, `sql-analysis`, `performance` (ils exécutent une requête ou du code) : exposés, **sans** annotation `IsReadOnly` ; les autres sont `IsReadOnly` + `IsIdempotent` |
| Schéma d'arguments | Déclaré explicitement par chaque scanner (`getOptionSchema()`), avec heuristique de repli pour les scanners tiers |
| Boost | `resources/boost/guidelines/core.blade.php` + `resources/boost/skills/devtoolbox-analysis/SKILL.md` |

## 3. Architecture

### 3.1 Fichiers

```
src/Mcp/
├── DevToolboxServer.php        # extends Laravel\Mcp\Server
├── ToolFactory.php             # ScannerRegistry → list<ScannerTool>
├── ScannerTool.php             # abstract, extends Laravel\Mcp\Server\Tool
├── ReadOnlyScannerTool.php     # #[IsReadOnly] #[IsIdempotent]
├── ActiveScannerTool.php       # aucune annotation
├── OptionSchemaCompiler.php    # schéma interne → Illuminate\JsonSchema + règles de validation
└── ResponseTruncator.php       # garde-fou de taille
src/Scanners/OptionSchemaInferrer.php   # getAvailableOptions() (texte) → schéma interne (repli) ; sans dépendance à laravel/mcp
resources/boost/guidelines/core.blade.php
resources/boost/skills/devtoolbox-analysis/SKILL.md
```

`OptionSchemaInferrer` et `ResponseTruncator` ne dépendent pas de laravel/mcp. Aucune autre classe de `src/Mcp/` n'est chargée si `laravel/mcp` est absent : PHP ne résout
la classe parente qu'à l'instanciation, et l'enregistrement est gardé par `class_exists`.

### 3.2 Enregistrement (`LaravelDevtoolboxServiceProvider::boot()`)

```php
if (class_exists(\Laravel\Mcp\Facades\Mcp::class)
    && config('devtoolbox.mcp.enabled', true)
    && $this->app->environment(config('devtoolbox.mcp.environments', ['local', 'testing']))) {
    \Laravel\Mcp\Facades\Mcp::local('devtoolbox', DevToolboxServer::class);
}
```

### 3.3 `DevToolboxServer`

- Attributs : `#[Name('DevToolbox')]`, `#[Version(...)]` (valeur : `Composer\InstalledVersions::getPrettyVersion('grazulex/laravel-devtoolbox')`, repli `'dev'`), `#[Instructions(...)]` (une phrase : introspection en lecture d'une app Laravel ; les tools `sql-trace`, `sql-analysis`, `performance` exécutent réellement du code).
- Constructeur : `$this->tools = app(ToolFactory::class)->make(app(ScannerRegistry::class))`.
- `boot()` : si `! app()->environment(config('devtoolbox.mcp.environments'))`, chaque tool répond `Response::error('DevToolbox MCP is disabled in this environment.')`. Cette seconde garde protège contre un `.mcp.json` copié sur un serveur.

### 3.4 `ToolFactory`

```php
final class ToolFactory
{
    /** @var list<string> scanners qui exécutent du code (pas read-only) */
    public const ACTIVE_SCANNERS = ['sql-trace', 'sql-analysis', 'performance'];

    /** @return list<ScannerTool> */
    public function make(ScannerRegistry $registry): array;
}
```

Pour chaque `[$name, $scanner]` du registre : instancie `ActiveScannerTool` si `$name ∈ ACTIVE_SCANNERS`, sinon `ReadOnlyScannerTool`. Nom du tool : `devtoolbox-<name>` (préfixe pour éviter les collisions avec Boost : `database-schema`, `application-info`…). Titre : `Str::headline($name)`. Description : `$scanner->getDescription()`.

### 3.5 `ScannerTool`

```php
abstract class ScannerTool extends Tool
{
    public function __construct(
        protected readonly ScannerInterface $scanner,
        string $name, string $title, string $description,
        protected readonly OptionSchemaCompiler $compiler,
        protected readonly ResponseTruncator $truncator,
    ) { $this->name = $name; $this->title = $title; $this->description = $description; }

    public function schema(JsonSchema $schema): array
    {
        return $this->compiler->toJsonSchema($this->scanner->getOptionSchema(), $schema);
    }

    public function handle(Request $request): Response
    {
        $options = $request->validate($this->compiler->toValidationRules($this->scanner->getOptionSchema()));

        try {
            $result = $this->scanner->scan($options + ['format' => 'array']);
        } catch (\Throwable $e) {
            Log::debug('[devtoolbox.mcp] scanner failed', ['scanner' => $this->scanner->getName(), 'exception' => $e]);

            return Response::error(sprintf('%s: %s', $this->scanner->getName(), $e->getMessage()));
        }

        return Response::structured($this->truncator->truncate($result, $this->scanner->getOptionSchema()));
    }
}
```

`outputSchema()` n'est pas défini en v1 (objet libre).

## 4. Schéma d'options typé

### 4.1 Contrat

`ScannerInterface` est **inchangée**. `AbstractScanner` gagne :

```php
/**
 * @return array<string, array{
 *     type: 'boolean'|'string'|'integer'|'array'|'object',
 *     description: string,
 *     default?: mixed,
 *     enum?: list<string>,
 *     required?: bool
 * }>
 */
public function getOptionSchema(): array
{
    return OptionSchemaInferrer::fromDescriptions($this->getAvailableOptions());
}

public function getAvailableOptions(): array
{
    return array_map(fn (array $option): string => $option['description'], $this->getOptionSchema());
}
```

Pas de récursion entre les deux méthodes : `AbstractScanner::getOptionSchema()` n'appelle
`getAvailableOptions()` que si la sous-classe la surcharge (détection par
`ReflectionMethod::getDeclaringClass()`), sinon elle retourne `[]`. Trois cas :

| La sous-classe surcharge… | `getOptionSchema()` | `getAvailableOptions()` |
|---|---|---|
| `getOptionSchema()` (les 16 scanners du package) | la sienne | dérivée du schéma |
| `getAvailableOptions()` seulement (scanner tiers existant) | inférée depuis ses descriptions | la sienne |
| aucune | `[]` | `[]` |

### 4.2 Les 16 scanners déclarent leur schéma

Chaque scanner remplace son `getAvailableOptions()` par `getOptionSchema()` avec des types
exacts, `default` et `enum` quand ils existent (ex. `method` de `sql-trace` : enum des
verbes HTTP, défaut `GET`). Les options internes de `getDefaultOptions()` (`format`,
`include_metadata`, `paths`, `exclude`) ne figurent **pas** dans le schéma : le tool
force `format = array`.

### 4.3 `OptionSchemaInferrer` (repli)

Heuristique sur la description textuelle : contient `(array)` → `array` ; clé dans
`{route, url, method, model, class, name, pattern, path, file, connection, table, column}`
→ `string` ; clé dans `{limit, threshold, top, lines, count}` → `integer` ; description
contenant `JSON object` → `object` ; sinon `boolean`. Testé unitairement.

### 4.4 `OptionSchemaCompiler`

- `toJsonSchema(array $schema, JsonSchema $factory): array` : `boolean()`/`string()`/`integer()`/`array()`/`object()` + `->description()`, `->default()`, `->enum()`, `->required()`.
- `toValidationRules(array $schema): array` : `boolean`, `string`, `integer`, `array`, `in:…` ; `required` si déclaré, sinon `sometimes|nullable`.

## 5. Réponses et garde-fous

### 5.1 Taille (`ResponseTruncator`)

Config `devtoolbox.mcp.max_response_bytes` (défaut `262144`). Si `strlen(json_encode($result))`
dépasse la limite :

1. Conserver toutes les valeurs scalaires de premier niveau (dont `count`, métadonnées).
2. Trouver la **première** clé de premier niveau dont la valeur est une liste, la tronquer
   par dichotomie au plus grand préfixe qui tient dans la limite.
3. Vider les autres listes/objets volumineux de premier niveau (remplacés par `null`).
4. Ajouter `_truncated => ['original_items' => n, 'kept_items' => k, 'hint' => 'Refine with options: a, b, c']`
   où les options viennent du schéma du scanner.

Le JSON résultant est toujours valide et ≤ limite.

### 5.2 Erreurs

- Exception d'un scanner → `Response::error('<scanner>: <message>')`, log `debug` avec l'exception. Jamais de stack trace dans la réponse.
- Arguments invalides → gérés par `$request->validate()` de laravel/mcp (erreur JSON-RPC standard).

### 5.3 Environnement

Deux gardes (enregistrement + `boot()`), voir §3.2 et §3.3.

## 6. Boost

### 6.1 `resources/boost/guidelines/core.blade.php` (≈40 lignes)

- Une phrase sur DevToolbox.
- Liste des tools `devtoolbox-*` avec une ligne chacun ; marquer les 3 actifs (« exécute réellement la requête »).
- Règles : avant de modifier/supprimer une route → `devtoolbox-routes` avec `detect_unused` ; où est utilisé un modèle/middleware → `devtoolbox-model-usage` / `devtoolbox-middleware-usage` plutôt que grep ; audit sécurité → `devtoolbox-security` ; ne jamais lancer `devtoolbox-sql-trace` hors local.
- Repli sans MCP : équivalent Artisan `php artisan dev:<cmd> --format=json`.

### 6.2 `resources/boost/skills/devtoolbox-analysis/SKILL.md`

Frontmatter `name: devtoolbox-analysis`, `description`. Sections « When to use » et 4
recettes : audit des routes non protégées ; chasse au N+1 (`sql-analysis` puis `sql-trace`
sur la route incriminée) ; impact d'un changement de modèle (`model-usage` + `db-column-usage`) ;
boot lent (`provider-timeline`, `container-bindings`).

## 7. Configuration et documentation

`config/devtoolbox.php` :

```php
'mcp' => [
    'enabled' => env('DEVTOOLBOX_MCP_ENABLED', true),
    'environments' => ['local', 'testing'],
    'max_response_bytes' => 262_144,
],
```

README : section « MCP Server » — installation (`composer require laravel/mcp --dev`),
enregistrement (`claude mcp add devtoolbox php artisan mcp:start devtoolbox`, snippet
`.mcp.json`, Cursor), liste des tools, garde d'environnement, Boost.
`dev:about+` affiche l'état MCP (laravel/mcp installé ? activé ? environnement autorisé ?).

CHANGELOG : entrée `[v1.7.0]` — Added (MCP server, Boost guidelines/skill, `getOptionSchema()`),
Changed (`getAvailableOptions()` dérivé du schéma). `composer.json` : `suggest` laravel/mcp,
`require-dev` `laravel/mcp: ^1.0`, keywords `mcp`, `ai`, `boost`.

## 8. Tests

Tous les tests MCP portent le groupe `mcp`.

| Niveau | Cas |
|---|---|
| Unit | `OptionSchemaInferrer` : chaque règle heuristique ; `OptionSchemaCompiler` : chaque type → JsonSchema et règles de validation attendus ; `ToolFactory` : 16 tools, noms `devtoolbox-*`, classe ReadOnly/Active correcte ; `ResponseTruncator` : gros jeu de données → JSON valide ≤ limite, `_truncated` présent, scalaires conservés |
| Par scanner (paramétré sur le registre) | `getOptionSchema()` : types valides, descriptions non vides, `enum`/`default` cohérents ; `getAvailableOptions()` égal aux descriptions du schéma ; aucune option interne (`format`…) dans le schéma |
| Tools bout en bout (`DevToolboxServer::tool(...)`) | `routes`, `models`, `middleware-usage` → `assertOk()` + structure attendue ; `sql-trace` sur une route de test → ok ; argument de mauvais type → erreur de validation ; scanner qui lève (stub enregistré dans le registre) → `Response::error` sans trace |
| Garde | serveur absent du registrar si `enabled=false` / env `production` ; `boot()` refuse hors env autorisé |
| Sans laravel/mcp | job CI dédié : `composer remove laravel/mcp --dev` puis `pest --exclude-group mcp` → la suite existante passe |
| Matrice | inchangée (PHP 8.3/8.4 × L12/L13 × lowest/stable) ; en L12 prefer-lowest, laravel/mcp impose `laravel/framework ≥ 12.41.1`, accepté |

## 9. Risques

- **API interne de laravel/mcp** (propriétés `$name/$title/$description`, `$tools` acceptant des instances) : v1.0 les expose, mais ce n'est pas un contrat documenté. Atténuation : tests bout en bout qui casseraient à la première régression ; contrainte `^1.0`.
- **Volume de sortie** : couvert par la troncature ; la pagination viendra si le besoin est confirmé.
- **Scanners actifs** : double garde d'environnement + annotations honnêtes ; documenté en gras.
