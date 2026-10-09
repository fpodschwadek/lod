# EXT:lod — Upgrade History

Chronological log of upgrade steps carried out on this extension. Newest entries at the bottom.

EXT:lod is used by more than one application, so every entry has to state what consuming projects must change on their side. Entries that require action carry a **Required changes in consuming projects** section.

Write each paragraph and each list item as a single line. Do not hard-wrap prose — let the editor soft-wrap it. Line breaks are for separating paragraphs and list items, nothing else.

---

## 2026-09-05 — TYPO3 13.4: content negotiation, page type conditions and request handling

### Symptoms

Opening the API page in a browser produced an endless suffix: `/resource.html` answered `303 See Other` pointing at `/resource.html.html`, which then 404ed. Requesting the HTML representation directly (`/resource/about.html`) produced a `TypeError` in `ContentNegotiationService::setContentType()`. Every RDF serialisation (`.json`, `.rdf`, `.ttl`, `.nt`, `.ttls`) answered `200` with a completely empty body and the correct `Content-Type` header.

### Cause 1 — the `types.` TypoScript array does not exist in TYPO3 13

`ContentNegotiationService` derived the list of available representations from `$setup['types.']`, an array mapping `typeNum` to the name of the `PAGE` object rendering it. That array was never written by anyone's TypoScript: `TemplateService::generateConfig()` built it by scanning the setup for `PAGE` objects. `TemplateService` was removed in TYPO3 v13 (#100963) and its replacement, `FrontendTypoScriptFactory`, resolves the `PAGE` object for the current type by walking the AST without ever producing a `types.` entry.

With `types.` gone the map of available MIME types was always empty, which broke content negotiation in two different ways. For a request that already named a page type, `setContentType($availableMimeTypes[$pageType])` was called with `null` and threw. For a request without a page type, `array_search($contentType, [])` returned `false`, and because the surrounding `in_array()` and `array_search()` calls were non-strict, `false == 0` matched the entry for page type `0` — whose suffix is `.html`. The redirect target therefore became "current URL plus `.html`", which is exactly the `/resource.html` → `/resource.html.html` loop.

### Cause 2 — every `getTSFE().type` TypoScript condition silently evaluates to false

The six `PAGE` objects and their plugin configuration were each wrapped in `[(getTSFE() ? getTSFE().type : 0) == <typeNum>]`. `getTSFE()` still returns the frontend controller in 13.4, but `TypoScriptFrontendController::$type` was removed in v13 — it was already deprecated in v12 with the note *"$TSFE->type will be removed in TYPO3 v13.0. Use $TSFE->getPageArguments()->getPageType() instead."* Symfony's expression language reads the property with a plain `$obj->$property`, so the expression yields `null`, every condition compares `null == <typeNum>` and is false.

The consequence is that `jsonld.10`, `rdfxml.10`, `ttl.10`, `ttls.10` and `nt.10` were never assigned `lib.tx_lod.plugin` and the per-format template root paths were never applied: the serialisation pages rendered nothing at all. The HTML page type only kept working because the consuming project happened to override `html.10` unconditionally.

### Cause 3 — the service was handed a blank request

`ContentNegotiationService` took a `ServerRequest` as a constructor argument. The DI container has no meaningful request to autowire there, so it constructed a fresh, empty one — visible in the compiled container as `makeInstanceForDi(ContentNegotiationService::class, makeInstanceForDi(ServerRequest::class))`. `getHeaderLine('Accept')` was therefore always empty and Accept-header negotiation had never actually run; the class carried a `// To do: make sure the request is passed on to this service!` comment about precisely this. The service was also registered as a shared service while holding mutable negotiation state.

### Changes made

**Content negotiation is now configured explicitly.** The representations on offer are declared in TypoScript as `plugin.tx_lod.settings.contentNegotiation`, in the same file as the `PAGE` object that renders them. `plugin.tx_lod` is merged into every plugin's configuration by Extbase, so the declaration reaches the `Api`, `Vocabulary` and `Serializer` plugins alike. This replaces the dependency on core internals, and it also decouples the Fluid format from the `PAGE` object's TypoScript variable name, which previously doubled as the format by convention.

Rebuilding the old `types.` map by scanning the setup array for `PAGE` objects was considered and rejected. It works in 13.4, but `FrontendTypoScriptFactory` carries an explicit `@todo` about removing all `PAGE` objects from the setup array, and `getSetupArray()` throws in cached frontend scope.

**`ContentNegotiationService` is stateless.** It no longer takes a request in its constructor and no longer holds negotiation state. `negotiate(ServerRequestInterface $request, array $settings): ContentNegotiationResult` does the work and returns the new immutable `Digicademy\Lod\Dto\ContentNegotiationResult`, which carries the page type the request asked for, the page type the negotiation settled on, the MIME type and the Fluid format. `getAcceptedMimeTypes()` now takes the request as an argument too, and parses `q` factors numerically instead of sorting quality values as strings.

**`ApiController::aboutAction()` builds redirect URLs with the router.** It used to read `routeEnhancers.PageTypeSuffix.map` out of the site configuration, concatenate the suffix onto the raw request URI, and then repair the result with `str_replace('.html/', '/', $uri)`. All of that is gone: `UriBuilder::setTargetPageType()` plus `uriFor()` lets the router apply whatever the site configuration maps the page type to. Current plugin arguments are carried over, so search and paging state survives the redirect. A guard was added that suppresses the redirect when the generated URL addresses the path already being served, so a misconfiguration can never produce a redirect loop again.

**All eleven page type conditions were deleted rather than ported.** The `PAGE` objects assign `10 < lib.tx_lod.plugin` unconditionally, because only the `PAGE` object matching the current `typeNum` is ever rendered and the condition never bought anything. The per-format template root paths are registered unconditionally on distinct keys, because every format tree contains only files carrying its own extension (`Api/About.html`, `Api/About.jsonld`, `Api/About.nt`, …) and Fluid therefore resolves the right template from the Extbase request format alone. `plugin.tx_lod_serializer` in `setup.typoscript` had already been organised this way. The extension now uses no TypoScript condition at all, so there is nothing left to re-break when the condition API changes again.

**The Fluid format is pinned in `initializeAction()` instead of by TypoScript.** The conditions also set `plugin.tx_lod.format` per page type, and that setting is *not* inert: Extbase reads it as the request's default format in `RequestBuilderDefaultValues::fromConfiguration()`. It has to be right before the view exists, because `ActionController::processRequest()` calls `initializeAction()` first but resolves the view -- and with it the template, its format and its file extension -- *before* the action body runs. `withFormat()` inside an action is therefore too late to influence template resolution; `ApiController::aboutAction()` had always called it, and it only ever worked because the TypoScript condition had already set the right default. `ApiController::initializeAction()` now runs the negotiation and applies `withFormat()` there, which both removes the last condition and keeps the format from disagreeing with the negotiated representation. `SerializerController` already used this hook for the same purpose. Removing `plugin.tx_lod.format` without this would silently render every serialisation through the HTML template.

**RDF vocabulary prefixes are registered as ignored Fluid namespaces** in `ext_localconf.php`. The RDF/XML templates write vocabulary terms as XML elements (`<rdfs:seeAlso>`, `<rdf:Description>`, `<void:feature>`, …), and Fluid parses every `<prefix:tag>` as a ViewHelper call, throwing `UnknownNamespaceException` for prefixes it does not know. Registering `dc`, `hydra`, `owl`, `rdf`, `rdfs`, `schema` and `void` with a `null` value marks them ignored. This was only uncovered once the RDF/XML page type started rendering again; the templates had been unreachable, so the parse error had nowhere to surface.

**Remaining frontend globals were removed**, since `$GLOBALS['TSFE']` and `TypoScriptFrontendController` are deprecated as of 13.4 (#105230) and do not survive into v14.

- `ApiController`, `VocabularyController` and `SerializerController` passed `$GLOBALS['TSFE']->pageArguments` into the Fluid `environment` array. That property no longer exists in 13.4, so the value had silently been `null` and every `{environment.TSFE.pageArguments.*}` in the templates resolved to nothing. It now comes from the `routing` request attribute.
- `T3Resolver` called `TypoScriptFrontendController::getPagesTSconfig()`, removed in v13 (#100963), which made the `t3://` representation resolver fatal on any call. Page TSconfig is now built with `PageTsConfigFactory`, mirroring how the core link builders do it. The resolver also reads the request instead of `$GLOBALS['TYPO3_REQUEST']`, and its `ContentObjectRenderer` is given the request, which TYPO3 13 requires before any link can be built.
- `ItemMappingService` lost its `$GLOBALS['TSFE']->sL()` branch; the `LanguageServiceFactory` fallback that already sat next to it does the same job.
- `ImmediateResponseException` was replaced with `PropagateResponseException`, which is what controllers are supposed to throw so that outer middlewares still process the response.

**The Fluid `environment` array was flattened.** `environment.TSFE.pageArguments` became `environment.pageArguments` and `environment.TSFE.page` became `environment.page`. The `TSFE` level named a class that no longer supplies any of it.

### Required changes in consuming projects

1. **Declare the representations you offer.** Content negotiation no longer discovers page types on its own. For every LOD `PAGE` object the project adds beyond the ones shipped here, add a matching entry, and make sure `default` names the representation to fall back to when a client accepts nothing on offer:

   ```
   plugin.tx_lod.settings.contentNegotiation {
     default = 1991
     types.1991 {
       mimeType = text/html
       format = html
     }
   }
   ```

   The six representations shipped with EXT:lod (1991 html, 2004 rdfxml, 2011 ttl, 2013 nt, 2014 jsonld, 2021 ttls) declare themselves, including `default = 1991` and `apiDocumentationPageType = 2014`. A page type that is not declared is simply never negotiated to; it is not an error.

2. **Template root path keys 10–15 are reserved for EXT:lod.** Projects that override `plugin.tx_lod.view.*RootPaths` must use keys 20 and above. Register one key per serialisation unconditionally instead of switching a single key with a page type condition.

3. **Rename the `environment` keys** `environment.TSFE.pageArguments` to `environment.pageArguments` and `environment.TSFE.page` to `environment.page`. This affects project *templates* that override an EXT:lod partial and, less obviously, any project *PHP* that takes the array as a ViewHelper argument -- `$environment['TSFE']['pageArguments']` becomes `$environment['pageArguments']`. In this portal that was `CulturePortal\ViewHelpers\IriLinkViewHelper`.

4. **Changed PHP signatures**, relevant only to projects that call these directly:
   - `ContentNegotiationService::__construct()` no longer takes a request; `getContentType()`, `getFormat()`, `getAvailableMimeTypes()`, `setContentType()`, `setFormat()` and `setAvailableMimeTypes()` are replaced by `negotiate(ServerRequestInterface $request, array $settings): ContentNegotiationResult`.
   - `ContentNegotiationService::getAcceptedMimeTypes()` now requires a `ServerRequestInterface`.
   - `ResolverService::resolve()` takes the current `ServerRequestInterface` as a third argument.
   - `AbstractResolver::__construct()` takes the current `ServerRequestInterface` as a third argument; custom resolvers registered in `$GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['lod']['resolver']` must accept it.

5. **Be aware that the Serializer plugin inherits these paths.** Extbase merges `plugin.tx_lod` into every plugin's configuration, so `plugin.tx_lod.view.*RootPaths` reach `plugin.tx_lod_serializer` as well, where `plugin.tx_lod_serializer.view` overrules them key by key. EXT:lod's serializer declares its own paths on keys 10, 20, 30 and 40, which therefore win over the format paths on the same keys, but the format paths on 11-15 and on any project key remain visible to the serializer. In practice this only matters for a project that ships its own per-format partials *and* runs the serializer in a format other than the one it configures, since the serializer resolves `Serializer/Iri.<format>` for a single format at a time. It was true of the previous arrangement too -- the format paths were all written to key 10 and leaked in the same way, merely masked by the collision. Register serializer specific overrides on `plugin.tx_lod_serializer.view` rather than relying on the shared paths.

6. **Flush the caches after deploying.** `Services.yaml`, the TypoScript and the settings all live in compiled caches; none of the above takes effect before `cache:flush`.

### Verification done

Verified against the running development instance at `https://nfdi4culture.local/`, which reaches the extension code directly because `packages/` is bind mounted into the containers.

| Request | Before | After |
|---|---|---|
| `/resource` | 303 → `/resource.html` | 303 → `/resource/about.html` |
| `/resource.html` | 303 → `/resource.html.html` → 404 | 303 → `/resource/about.html` |
| `/resource/about.html` | 500 `TypeError` | 200, `text/html`, 162 KB |
| `/resource.json` | 200, **0 bytes** | 200, `application/ld+json`, parses as JSON |
| `/resource.rdf` | 200, **0 bytes** | 200, `application/rdf+xml`, well formed `<rdf:RDF>` |
| `/resource.ttl` | 200, **0 bytes** | 200, `text/turtle`, `@prefix` header |
| `/resource.nt` | 200, **0 bytes** | 200, `application/n-triples` |
| `/resource.ttls` | 200, **0 bytes** | 200, `text/x-turtlestar` |
| `/resource/.json` | 200, **0 bytes** | 200, Hydra entry point |

Single resource resolution was verified on a real IRI (`ccp5`): `/resource/ccp5` redirects to `/resource/ccp5/about.html`, and `.json`, `.ttl`, `.rdf` and `.nt` each return 200 in their own format. Both redirect chains complete in exactly one hop.

Accept header negotiation was verified on the abstract resource and now works for the first time -- it could not have worked before, because the service was constructed with a blank request. `Accept: text/turtle` → `/resource.ttl`, `application/ld+json` → `/resource.json`, `application/rdf+xml` → `/resource.rdf`, `application/n-triples` → `/resource.nt`, `text/html` → `/resource/about.html`. The same holds for a single resource.

Nine content negotiation scenarios were additionally exercised against the service in isolation, covering `q`-weighted preference, a missing Accept header, an Accept header naming nothing on offer, and an entirely unconfigured type map. The last case is the important guard: it resolves to "no redirect target" instead of falling back onto page type 0, so the self-appending redirect cannot recur.

Static analysis: `php -l` clean across `Classes/`; PHPStan level 5 reports 18 errors, all pre-existing and none in the changed code; `php-cs-fixer` clean on every changed file; `typoscript-lint` reports no parse error in any changed file.

Two `instanceof.alwaysTrue` findings were resolved on the way by correcting the `@var` of `ApiController::$resource` to `Iri|null`, which is what the code actually holds. `packages/lod/phpstan.neon` had `scanDirectories: - ../..`, which resolved to the project root and made PHPStan abort before analysing anything; it was dropped, since running from the project root lets Composer's autoloader resolve core and vendor classes on its own.

### Open

- **Cosmetic:** when the abstract resource is requested *with* plugin arguments (`/resource.html?tx_lod_api[query]=…`), the redirect target carries `tx_lod_api[action]=about&tx_lod_api[controller]=Api` and a cHash. The Extbase route enhancer keys its routes on `iri` and `apiDocumentation`; with neither present no route can be built, so `uriFor()` falls back to plain query arguments. The URL resolves correctly (verified 200 in one hop) -- it is only uglier than the string concatenation it replaced, which preserved the raw query string. Adding a route with an empty `routePath` to the application's `ApiPlugin` enhancer would absorb them.
- `ApiController::listAction()` dispatches `$this->iriRepository->$findMethod(...)` where `$findMethod` is either `findByArguments` or `findAll`, and calls both with two arguments. PHPStan flags the `findAll()` case. Pre-existing, and fixing it means settling the repository signatures.

---

## 2026-09-23 — TYPO3 13.4: `EnhancedGroupElement` could no longer see the inherited `IconFactory`

### Symptoms

Every group field in the backend died as soon as it was rendered, with `Call to a member function getIcon() on null`. In the NFDI4Culture portal this surfaced as a failing AJAX request when expanding a relation record, because `tx_academy_domain_model_relations` reaches its partner entities through `type => group` fields, but it is not specific to that application: `ext_localconf.php` registers `EnhancedGroupElement` as an XCLASS of core's `GroupElement`, so the breakage applied to every group field in any consuming project.

### Cause

`EnhancedGroupElement` is a copy of core's group element `render()` with two `@metacontext` changes to the hardcoded field-control HTML, and it reads `$this->iconFactory` six times. Up to TYPO3 12.4 that property was inherited: `AbstractFormElement` declared `protected $iconFactory` and populated it in its own constructor with `GeneralUtility::makeInstance(IconFactory::class)`. TYPO3 13 removed it from `AbstractFormElement` altogether and made it a private promoted constructor property of `GroupElement` itself. A private property of a parent class is not visible in a subclass, so `$this->iconFactory` read an undeclared property, which is `null`, and the first `getIcon()` call on it was fatal.

There is no changelog entry for this. The only related one is `#101133 IconFactory->getIcon() signature change`, which this file already handled correctly — it passes `IconSize::SMALL`. The visibility change went unannounced, exactly like the `TcaInline::resolveConnectedRecordUids()` signature change that broke `EXT:academy` the same day.

It was, however, statically detectable, unlike that one. PHPStan at the level this extension already runs reported all six reads as `property.private`, "Access to private property $iconFactory of parent class GroupElement" — they had been sitting unexamined in the extension's error backlog.

### Fix

`EnhancedGroupElement` now declares its own constructor with a promoted `private readonly IconFactory $iconFactory`. The signature deliberately mirrors `GroupElement`'s, because TYPO3 resolves constructor arguments for the class being overridden and hands them to the override — `AbstractServiceProvider::new()` calls `GeneralUtility::makeInstanceForDi()`, which resolves the XCLASS name and instantiates it with the arguments gathered for the original. Should core add an argument there, PHP passes the extra one and ignores it; should it drop the `IconFactory`, this now fails loudly with an `ArgumentCountError` instead of silently reading null again.

`parent::__construct()` is deliberately not called, since `render()` is a full override that never reads `GroupElement`'s own private copy. The now-dead `use TYPO3\CMS\Core\Imaging\Icon;` import was replaced by the `IconFactory` one; nothing else referenced `Icon`.

### Still open

The class remains a copy of a core method body, which is what let this go unnoticed. Whitespace-normalised, 166 of its roughly 253 `render()` lines differ from 13.4 core against only two `@metacontext` markers, and it is missing `$recordTypeValue = $this->data['recordTypeValue'] ?? null;` that 13.4 core added. The file header notes that registering a proper FormEngine node instead of XClassing was tried and had "problems in data handling", so replacing it is not a trivial swap, but it is the right long-term direction.

### Required changes in consuming projects

None. The fix is contained in the extension and restores the behaviour projects already expected. Any project on TYPO3 13.4 that renders a group field in the backend needs this commit, since without it every such field is fatal.

### Verification

`php -l` clean. PHPStan against `packages/lod/phpstan.neon` drops from 18 errors to 12, the six removed being exactly the `property.private` reads above, and the file now analyses clean on its own.

Checked in a throwaway `1drop/php-utils:8.5` container against the project autoloader: the class still implements `NodeInterface`, so it keeps the `backend.form.node` autoconfigure tag that makes it a public, non-shared service; it declares its own constructor; that constructor's signature is identical to core `GroupElement`'s; the `iconFactory` property now resolves to this class rather than the parent; and an instance carries an `IconFactory` rather than null.

A repository-wide audit was run alongside: every class under a `packages/*/Classes` tree that extends a TYPO3 core class was loaded and each `$this->x` read compared against what is actually accessible to it. `EnhancedGroupElement` was the only case of a subclass reading a parent's private property, and it no longer appears. Not fixed, and unrelated: `N4C\CultureRegistry\Domain\Model\Data` and `Software` read nine undeclared properties between them, and several `culture_registry` and `lod` constructors still use implicitly nullable parameters that PHP 8.4 deprecates.

---

## 2026-09-23 — `EnhancedGroupElement` no longer copies core's `render()`

### Why

The `IconFactory` fix recorded above treated the symptom. The cause was that this class held a copy of core's `GroupElement::render()` — roughly 300 lines — in order to change one CSS class, so it could not notice when core moved `$iconFactory` into a private property. A copy of a core method body cannot be kept correct across upgrades, and this was the second fatal of the day from that same pattern; `EXT:academy` had one a few hours earlier, where a copy of `TcaInline::resolveRelatedRecords()` went on calling a `protected` method whose signature had changed.

### What the class actually needed

Only two things, both of which can be done around `parent::render()`:

The field controls are laid out horizontally rather than vertically — a single CSS class, `btn-group-vertical` to `btn-group-horizontal`. Note that core emits *two* button groups in a group field and the copy changed only one of them: the move and delete controls stayed vertical and must continue to. TYPO3 13.4 distinguishes the two asides with `--move` and `--field-control` modifiers (`GroupElement.php:292` and `:347`), so the replacement is anchored on `form-wizards-item-aside--field-control` and swaps only the `btn-group-vertical` that follows it. `preg_replace()` answering null is caught, leaving core's markup rather than losing it.

A field whose value arrives as a bare uid instead of a resolved item list is normalised before core sees it. This is the `l10n_display => defaultAsReadonly` case, and it is still needed: 13.4 core assigns `itemFormElValue` at `GroupElement.php:124` and iterates it at `:139` without any scalar guard. Core reads the value from `$this->data['parameterArray']['itemFormElValue']`, so it is rewritten there before delegating. A value that is already an array passes through untouched, and an empty value or a uid of 0 becomes an empty list.

### Result

346 lines become 102, most of them comment. The class declares no properties and no constructor of its own, so it inherits `GroupElement`'s and dependency injection populates core's private `$iconFactory` exactly as core intends — which makes the `IconFactory` constructor added in `b8b6ab9` unnecessary, and it has been removed again. That failure mode is gone rather than worked around. `MathUtility`, `StringUtility`, `JavaScriptModuleInstruction` and `GeneralUtility` are no longer imported; only `GroupElement` and `BackendUtility` remain, and both are used.

The worst a future core change can now do here is stop matching the field-control pattern, in which case the buttons revert to vertical. That is a cosmetic regression instead of a fatal one.

A side effect worth knowing: the copy still emitted TYPO3 12.4 markup — `form-wizards-items-aside`, without the `--field-control` modifier — so these fields have been rendering stale class names that 13.4 backend CSS does not target. Delegating to core restores the current markup.

### Still open

`ext_localconf.php:119` still registers this as an XClass of `GroupElement`, so it applies to every group field in the backend rather than only the statement table and triple composer fields the header names. That was kept deliberately, so appearance does not change anywhere. Scoping it properly would mean registering a FormEngine node for a custom `renderType` and setting that `renderType` on the fields that want it, which would revert other group fields to vertical controls and would need TCA changes in consuming extensions.

### Required changes in consuming projects

None. Behaviour and appearance are unchanged, and the extension no longer carries a copy of core code that can silently rot. Projects that took `b8b6ab9` should take this too, since it supersedes that fix.

### Verification

`php -l` clean. PHPStan against `packages/lod/phpstan.neon` stays at 12 errors, its baseline after `b8b6ab9`, and this file analyses clean on its own.

Fourteen assertions were checked in a throwaway `1drop/php-utils:8.5` container. The button-group swap was exercised against a fixture built from core's own source — the literal markup strings core appends around each of the two asides, joined as `render()` joins them — and it confirms the field-control group becomes horizontal, the move group stays vertical, exactly one class is swapped, and nothing else about the markup changes. The normalisation was checked for an already-resolved list, an empty array, a uid of 0, an empty string, null, and a config without `allowed`; the branch that actually loads a record needs a database and was not covered here. The class shape was checked too: no constructor of its own, no properties of its own, and no mention of `$this->iconFactory` anywhere.

A repository-wide audit was re-run, loading every class under a `packages/*/Classes` tree that extends a TYPO3 core class and comparing each `$this->x` read against what is accessible to it. This class no longer appears.

## 2026-10-09 — `DataHandler` hook no longer assumes `sys_language_uid` is submitted

`Classes/Hooks/Backend/DataHandler.php`, `processDatamap_postProcessFieldArray()`, read `$fieldArray['sys_language_uid']` without a guard for the IRI, statement and representation tables. DataHandler removes unchanged fields from `$fieldArray` before this hook runs, so on an ordinary update the key is missing and PHP emits `Undefined array key "sys_language_uid"`. The warning existed in 12.4 too; it surfaces in projects whose `SYS.errorHandlerErrors` includes `E_WARNING`.

Because a missing key reads as `null` and `null <= 0` is true, an update of a stored translated record (language above 0) was also handled as a default-language record and rewritten to `sys_language_uid = -1`, rather than having its field array blanked as intended.

### Change

New private method `resolveSysLanguageUid()`: the submitted value if present, otherwise the stored record's value via `BackendUtility::getRecord()`, otherwise 0 (new records with a `NEW…` id, or records not found). The hook returns early for tables other than the three LOD tables, so no query is added elsewhere.

### Required changes in consuming projects

None. Records in language 0 or -1 behave exactly as before.

### Verification

`php -l` clean. PHPStan against `phpstan.neon` stays at 12 errors and this file analyses clean. Seven stubbed behavioural assertions covering the missing key, stored languages 0 and 2, new records, a submitted key, an unrelated table and a missing record all pass with warnings promoted to exceptions.

## 2026-10-09 — Optional keys guarded in identifier generation, table tracking, IRI type filter and API search

Commits `0e80692` and `4d12b78`. Under PHP 8 every read of an absent array key raises a warning, which projects that pass `E_WARNING` to TYPO3's error handler show in the backend. An audit of `Classes/` found the following reads on routine paths and guarded them; each default reproduces the previous outcome of the missing key reading as `null`.

- `Hooks/Backend/DataHandler`: `t3_origuid` (core passes it only when copying), `$record['record']` (the bnode table has no such column), the optional `identifierGenerator` TSConfig blocks, `tableTracking.<table>.track`, and `sys_language_uid` in `processDatamap_afterDatabaseOperations()` for tables without a language column. A deleted namespace record no longer reaches `array_key_exists()` as null (`TypeError`); a deleted parent record of an IRI falls back to the IRI's pid.
- `Generator/AbstractIdentifierGenerator`, `UuidIdentifierGenerator`: `$this->record['type']` (absent for bnodes) and the optional `entityPrefix`, `propertyPrefix`, `bnodePrefix`, `xmlConformance` keys.
- `Service/TableTrackingService`: every `iri.`/`representations.`/`statements.` setting was read in both its plain and its `key.` form, where TSConfig sets only one; the 20 ternaries now use a `stdWrapOptional()` helper with identical semantics. `hideUnhide`, `deleteUndelete`, `representations.`, `statements.`, `iriPidList(.)` and the record's `hidden` field are guarded too.
- `Utility/Backend/IriUtility::filterByType()`: the empty value list of a cleared field, the pid of a deleted IRI, and `iriTypeFilter` per field.
- `Domain/Repository/IriRepository::findByArguments()`: the optional `query`, `subject`, `predicate`, `object` arguments and `list.additionalPidList`.

### Required changes in consuming projects

None. The only observable difference: an IRI created by table tracking for a table without a `hidden` column gets `hidden = 0` instead of `null`, which DataHandler stored as 0 anyway.

### Verification

`php -l` clean; PHPStan against `phpstan.neon` stays at 12 errors, none on a changed line. Stubbed behavioural checks: 7 scenarios through `processDatamap_afterDatabaseOperations()` (the unfixed code reproduces the reported `t3_origuid` warning), a side-by-side run of the original and fixed `TableTrackingService` on 7 scenarios with identical datamaps apart from `hidden` above and warnings down from up to 39 per call to 0, and 4 scenarios through `IriUtility::filterByType()`.

### Still open

Not changed here, each needing a behavioural decision: the `returnUrl`/`pid` reads in `EnhancedAddController` (a `TypeError` on two redirect paths), `ApiController.php:344` (`TypeError` when `apiDocumentation.keys` is unset), `VocabularyController.php:68`, the `(int)` cast of `label_language` in `TableTrackingService` (a language code such as `en` becomes 0), the `(int)` cast of the TCA default `'1,2'` in `IriUtility`, `IsoCodeService::renderIsoCodeSelectDropdown()` taking `$conf` by value, and the non-existent `ForeignRecordIdentifierGenerator` named in `Configuration/TSConfig/setup.tsconfig`.

## 2026-10-09 — The enhanced add wizard is replaced by core's add wizard

Commit `d2494c1`. Clicking "Create new IRI" (or blank node, literal) on a statement or graph failed with `Too few arguments to function Digicademy\Lod\Backend\Form\Controller\Wizard\EnhancedAddController::__construct(), 0 passed`. The Rector run (`540b6c4`) had given the controller a constructor with `UriBuilder`, but without `#[AsController]` the class is a private service, so `Dispatcher::getCallableFromTarget()` fell back to `GeneralUtility::makeInstance()` without arguments.

Fixing that alone would only have exposed the next failures, because the popup design of `e004dac` could not work in 13.4:

- The popup script `Resources/Public/JavaScript/EnhancedAddRecord.js` was never loaded: `EnhancedAddRecord::render()` returned the module instruction under the numeric key `0` instead of `javaScriptModules`, the file was a RequireJS `define()` module (RequireJS was removed in 13.0, Breaking #101266), and `../typo3conf/ext/...` is not an import-map specifier. The wizard therefore ran in the content frame, replacing the parent form, and ended on a blank page when it answered `<script>close();</script>`.
- `returnUrl` was deliberately not sent (the popup closed itself instead), so both redirects back to the parent form passed `null` to `GeneralUtility::sanitizeLocalUrl(string)`, a `TypeError`.
- The block that writes the new record into the parent field was unreachable, because `doClose=1` always accompanied `returnEditConf` and was checked first, and it still used the 12.4 `FormDataCompiler` API (constructor argument, one-argument `compile()`), so it would have failed as soon as it was reached.

Without the popup, `EnhancedAddController` was a copy of core's `AddController`, so it was removed along with its route `wizard_enhanced_add` (`Configuration/Backend/Routes.php`) and the JavaScript file. `EnhancedAddRecord` is now a subclass of core's `TYPO3\CMS\Backend\Form\FieldControl\AddRecord` whose only addition is the `iconIdentifier` option, needed because several of these controls sit on one field and core always renders `actions-plus`. Core's `wizard_add` resolves `###PAGE_TSCONFIG_ID###` and the other pid markers itself, writes the new record into the parent field according to `setValue`, redirects back to the parent form, and its `add-record.js` module routes the click through `FormEngine.preventFollowLinkIfNotSaved()`, so unsaved changes to the parent prompt for saving instead of being lost.

### Required changes in consuming projects

None for TCA: the `renderType` `enhancedAddRecord` and its `table`, `pid`, `setValue`, `title` and `iconIdentifier` options are unchanged. The `windowOpenParameters` option no longer has any effect. A project that linked to the `wizard_enhanced_add` route directly must use core's `wizard_add`. As before, a `###PAGE_TSCONFIG_ID###` pid needs `TCEFORM.<table>.<field>.PAGE_TSCONFIG_ID`; without it core's wizard resolves the pid to 0.

### Verification

`php -l` clean. PHPStan against `phpstan.neon` drops from 12 to 11 errors, the removed controller having carried one. The subclass was rendered against the real core `AddRecord` with a recording `UriBuilder`: custom icon applied, link to `wizard_add`, `returnUrl` sent, `table`/pid marker/`setValue` and parent table/field/uid passed through, core's `add-record.js` under `javaScriptModules`, `title` kept, and core's icon without warning when `iconIdentifier` is not set. The wizard round trip itself (create, write into the parent field, return) is core code and needs checking in the backend.

## 2026-10-09 — IRI label no longer assumes the record is saved

Creating a new IRI from a statement's field control raised `PHP Warning: Trying to access array offset on null in .../Classes/Utility/Backend/LabelUtility.php line 82`. This is not a TYPO3 13 API change: the file was unchanged since 12.4. It surfaced now because the "Create new IRI" control works again since the previous entry, and core's `wizard_add` opens the IRI form for a record that does not exist yet.

### Cause

`LabelUtility::iriLabel()` is the `label_userFunc` and `formattedLabel_userFunc` of `tx_lod_domain_model_iri`. When page TSconfig sets `tx_lod.settings.iriLabel.displayPattern`, it re-fetched the IRI with `BackendUtility::getRecord(…, (int)$row['uid'])` and replaced `$parameters['row']` with the result unconditionally. A record that has not been saved has a `NEW…` placeholder uid, `(int)'NEW…'` is `0`, `getRecord()` returns `null`, and the row TYPO3 had supplied was thrown away. The namespace lookups are guarded with `isset()` and stayed silent; the `###IRI_VALUE###` and `###IRI_LABEL###` replacements were not and raised the warning. A deleted or missing record produced the same warning, and the label was then left with every marker unreplaced.

### Changes made

- The record is only re-fetched for a positive integer uid, and the passed-in row is only replaced when `getRecord()` returns an array. For a new record the label is therefore built from the form's own row, e.g. `n4c:E99` instead of the raw pattern.
- `pid`, `uid`, `value` and `label` are read as optional keys.
- The markers are replaced with `str_replace()` instead of `preg_replace()`. With `preg_replace()` a `$1` or `\1` in a namespace prefix, IRI value or label was read as a back-reference, so an IRI value `E$1` was shown as `E`.

### Required changes in consuming projects

None. Labels of saved records are unchanged, apart from values containing `$` or `\` digit sequences, which are now shown verbatim.

### Verification

`php -l` clean; PHPStan against `phpstan.neon` reports no error in the file and 11 for the extension, the existing baseline. A harness ran the old and new file against a stubbed `BackendUtility` for five cases (new record with pattern, new record with an empty row, existing record, deleted record, new record without pattern): the old file reproduced the warning at line 82 (and 86) in three cases; the new file raises none, and the existing-record and no-pattern labels are identical apart from the back-reference fix.

## 2026-10-09 — API documentation key check no longer throws when no keys are configured

`ApiController::apiDocumentationAction()` checked the requested `apiDocumentation` argument with `in_array($key, $this->settings['apiDocumentation']['keys'])`, unguarded. If `plugin.tx_lod.settings.apiDocumentation.keys` was missing (removed with `>`, or `lod`'s static TypoScript not included) or set as a scalar (`keys = api` instead of `keys.0 = api`), `in_array()` threw a `TypeError`. Because the argument comes from the request (`/<api page>/contexts/<anything>.json` via the `ApiPlugin` route enhancer), any visitor could turn that misconfiguration into an HTTP 500. The check is unchanged from 12.4; it came up in the undefined-array-key audit.

### Changes made

- Missing or non-array `keys` are treated as "no valid key": the action answers with the same 404 as for an unknown key. This matches the `Link` header code in `aboutAction()`, which already skipped the header when `keys` was not an array.
- The comparison is strict. TypoScript values are strings, so configured keys still match; a request value is no longer loosely compared against them.

### Required changes in consuming projects

None. A project without configured keys now gets a 404 for API documentation requests instead of a 500.

### Verification

`php -l` clean; PHPStan against `phpstan.neon` reports the same single pre-existing error in `ApiController.php` before and after, and 11 for the extension. The condition was checked in isolation for the default keys with `api`, `foo` and `0`, and for missing `apiDocumentation`, missing `keys`, scalar `keys` and an array argument: only the default with `api` passes, nothing throws. On the running portal `/resource/contexts/api.json` still answers 200 `application/ld+json` (the target of the `Link` header on `/resource.json`), and `/resource/contexts/foo.json` and `/resource/contexts/0.json` answer 404.

## 2026-10-09 — Hydra `Link` header no longer assumes a default API documentation key

`ApiController::aboutAction()` picks the key for the Hydra `apiDocumentation` `Link` header from `settings.apiDocumentation.keys`: the entry for the current page (`PID = KEYWORD`), otherwise entry `0`. The fallback read `keys[0]` unguarded, so a project that configured only per-page keys raised `Undefined array key 0` on every API request on a page without its own entry, and the header then linked to the API documentation route with no key — a URL that answers 404.

### Changes made

- The key is resolved as `keys[<page id>] ?? keys[0] ?? null`. If that does not give a non-empty string, there is no valid key and the `Link` header is omitted, consistent with the 404 `apiDocumentationAction()` gives for the same situation (entry above).
- The CORS headers are no longer tied to the `Link` header: they are still sent whenever `keys` is an array, exactly as before, also when no `Link` header is.

### Required changes in consuming projects

None. Projects with a default key `0` (as shipped) or a key for every API page see no difference.

### Verification

`php -l` clean; PHPStan unchanged at one pre-existing error in `ApiController.php` and 11 for the extension. Key resolution was compared old against new for six configurations: identical where a key resolves (default only, per-page with and without default); where none does (per-page miss, empty keys, nested value) the old code warned or produced an array, the new one omits the header. On the running portal `/resource.json` and `/resource/about.html` still send `Access-Control-Allow-Origin: *` and `Link: <…/resource/contexts/api.json>; rel="…hydra/core#apiDocumentation"`.

## 2026-10-09 — Vocabulary plugin: missing or unloadable vocabulary no longer breaks the page

`VocabularyController::showAction()` read `settings.general.selectedVocabulary` and used the result without checks. It failed in three ways, none of them new in 13.4 (the method is unchanged from 12.4 apart from the `environment` array):

- **No vocabulary selected**, e.g. a `lod_vocabulary` element created programmatically, imported or migrated by the CType wizard, whose FlexForm was never saved (`minitems = 1` is only enforced on save): `Undefined array key "selectedVocabulary"`, after which the element rendered without a vocabulary.
- **Selected vocabulary cannot be loaded** — deleted, hidden, outside its start/end time, or a stale uid after an import: `findByUid()` returned `null` and `$selectedVocabulary->getIri()` threw `Error: Call to a member function getIri() on null`, taking the whole page down.
- **Vocabulary without an IRI**, which the TCA allows (`iri` has `minitems = 0`): `Vocabulary::getIri(): Iri` threw a `TypeError` because it returned `null`.

### Changes made

- The setting is read with a fallback and cast explicitly, replacing the assignment-inside-`if` `(int)$uid = …` construct. Without a selection the element renders without a vocabulary, as before, but silently.
- If a vocabulary is selected but cannot be loaded, the element renders the same way and a **warning is logged**: `Vocabulary {uid} selected in content element {uid} cannot be loaded; it may be deleted, hidden or outside its start/end time.` The logger is injected into the controller's constructor (`Psr\Log\LoggerInterface`, channel = controller class), so it goes wherever the installation's `LOG` configuration sends warnings — by default `var/log/typo3_*.log`.
- `Vocabulary::getIri()` now returns `?Iri`. For a vocabulary without an IRI the controller skips the graph lookup and assigns `graph = null`; the vocabulary itself and the namespaces are still assigned.

### Required changes in consuming projects

- Code that calls `Vocabulary::getIri()` must handle `null`. Subclasses overriding it with return type `Iri` remain compatible (a narrower return type is allowed).
- Subclasses of `VocabularyController` that override the constructor must accept and pass on the new fourth argument `LoggerInterface $logger`.
- Flush the caches after updating: the compiled DI container still passes three constructor arguments until it is rebuilt.

### Verification

`php -l` clean on both files; PHPStan against `phpstan.neon` reports no error in either file before or after, 11 for the extension. The real `showAction()` was run old against new with stubbed repositories, view, configuration manager and logger for four cases. Old: the undefined-key warning; correct output for a vocabulary with IRI; `TypeError` for a vocabulary without IRI; `Error … getIri() on null` for an unloadable one. New: HTTP 200 in all four, identical assignments for the vocabulary with IRI, `graph = null` for the one without, and for the unloadable one no assignments plus the warning with vocabulary and content element uid. Not exercised on the running portal, which has no vocabularies and no live `lod_vocabulary` elements.

## 2026-10-09 — Table tracking stores label and comment languages as configured

`TableTrackingService` cast `tableTracking.<table>.iri.label_language` and `comment_language` to `int` when it created an IRI for a tracked record. Both columns are ISO 639-1 codes (`varchar(2) DEFAULT ''`, a select of language codes in TCA), so a configured `label_language = en` was stored as `'0'`, and an unconfigured language was stored as `'0'` rather than left empty, because the default was the integer `0`. Not an upgrade regression: the cast was introduced with the stdWrap configuration of table tracking in `f122d90` (2020-03-27) and the columns have been ISO codes since `9c859de` (2019-12-27), so every version since 2020 was affected. The 13.4 guard refactor (`4d12b78`) had kept the cast deliberately, pending this decision.

### Changes made

Both values are now taken as the stdWrap result without a cast, with `''` as the default, the same way `content_language` of the generated representations already was. A configured code — plain, `.value` or any other stdWrap — is stored as given, and an unconfigured language stays empty.

### Required changes in consuming projects

- None in configuration. Projects that configure a language get it stored from now on; check that the configured value is a two-letter code, since the columns hold two characters.
- Existing IRIs are not changed: rows already carrying `'0'` keep it. `'0'` is not rendered as a language tag (Fluid treats it as false), but projects that want clean data must update those rows themselves.

### Verification

`php -l` clean; PHPStan against `phpstan.neon` reports no error in the file, 11 for the extension. The real `stdWrapOptional()` was called with a stubbed `ContentObjectRenderer` for `label_language = en`, `label_language.value = en`, `label_language.field = <field>` and no configuration: the old cast produced `0` in all four cases, the new code `'en'`, `'en'`, the field's value and `''`.

## 2026-10-09 — `DataHandler` hook: no after-insert processing for records whose insert was suppressed

Localizing a record that has an IRI as inline child — reported for a product — raised `PHP Warning: Undefined array key "NEW…" in …/Classes/Hooks/Backend/DataHandler.php line 319`.

### Cause

DataHandler localizes inline children together with their parent, including IRIs, which are stored with `sys_language_uid = -1`. `processDatamap_postProcessFieldArray()` deliberately empties `$fieldArray` for IRIs, statements and representations in any language above 0, so `DataHandler::insertDB()` returns without inserting (there is no `pid`) and `substNEWwithIDs` gets no entry for the placeholder. Core still calls `processDatamap_afterDatabaseOperations()` with the `NEW…` id, and `generateIdentifier()` (line 319) and `generatePrefixValue()` (line 405) both resolved it through `$pObj->substNEWwithIDs[$id]` unguarded; the reads that followed warned on the resulting `null`, and `generatePrefixValue()` issued an `UPDATE … WHERE uid = 0`. The code is unchanged from 12.4, where the warnings were not reported and the updates matched no row.

### Changes made

`processDatamap_afterDatabaseOperations()` returns immediately for a `new` record without an entry in `substNEWwithIDs`. Nothing was inserted, so there is no identifier or prefix value to generate and nothing to track; previously `trackTables()` was skipped for these records only because the emptied field array happened to lack `sys_language_uid`.

### Required changes in consuming projects

None. A localized parent still gets no localized IRI, exactly as before; only the warnings and the no-op update are gone.

### Verification

`php -l` clean; PHPStan against `phpstan.neon` reports no error in the file before or after, 11 for the extension. The real hook was called with a stubbed `BackendUtility` and database connection. For a suppressed insert the old code raised the reported warning at line 319 and four more and issued `UPDATE … uid=0`; the new code returns without warnings or updates. For an inserted IRI both versions pass on to identifier and prefix-value generation and write `prefix_value` for the real uid; `trackTables()` could not be run outside a booted TYPO3 in either version.

## 2026-10-09 — Test setup aligned with the other extensions; unit tests for escaping, generators, resolvers and the DataHandler hook

### Test setup (`8c74f9d`)

Tests are run with this extension's own `codeception.yml` only (`codecept run <suite> -c packages/lod`), never through a configuration aggregating several extensions. The Codeception namespace is therefore `Tests`, the same as in the other extensions, which lets support classes be shared byte-identical: `Tests/Support/Helper/Typo3Module.php` is a copy of the one in `culture_portal` and must stay identical to it.

- `codeception.yml`: `namespace: Tests` (was `Digicademy\Lod\Tests`); `UnitTester` and `ContentNegotiationServiceTest` moved accordingly.
- `Unit` suite: documented as bootstrap-free; it runs in any PHP container with the project's `vendor/`.
- New `Integration` suite enabling `Typo3Module`, for tests that need a booted TYPO3. It boots against the configured instance and its database, so its tests must only read and must not depend on particular records. It has no tests yet.
- `composer.json`: the `Digicademy\\Lod\\Tests\\` `autoload-dev` mapping was dropped. Inside a project it never applied (path packages' `autoload-dev` is not part of the project autoloader) and it no longer matched; Codeception loads test and support classes itself. The `test` script passes `-c .`, and `test-integration` was added.
- `README.md`: testing section.

Data providers have to be declared with `Codeception\Attribute\DataProvider` (or a `@dataProvider` docblock): Codeception's Unit loader resolves providers itself and ignores PHPUnit's `#[DataProvider]` attribute.

### Unit tests (`853af95`)

82 tests, 168 assertions: `EscapeLiteralViewHelper`, `LangDatatypeViewHelper`, `RemoveEmptyLinesViewHelper`, `FilterIriNamespacesViewHelper`, the identifier generators and `IdentifierGeneratorService`, `HttpResolver`/`HttpsResolver`, and the `DataHandler` hook (language handling, `record`/`subject` synchronisation in inline contexts, and the suppressed-insert guard — run once against the hook before `b445d68`, where it fails with the originally reported warning). `Tests/Support/FailOnPhpErrorsTrait.php` turns PHP warnings into exceptions for tests that pin down warning-free behaviour, as the development context does.

### Defects found and fixed

- `6e90784` — `ForeignRecordTablenameUidIdentifierGenerator` read `includeTablename` and `record` unguarded; a generator configured without `includeTablename` aborted saving an IRI where warnings are exceptions.
- `e8767dd` — `EscapeLiteralViewHelper` produced invalid output: N-Triples literals were escaped with `json_encode()`, which writes `/` as `\/`, an escape N-Triples does not have; Turtle literals were escaped with `addslashes()`, which writes NUL as `\0`. N-Triples and JSON-LD now use `JSON_UNESCAPED_SLASHES`; Turtle escapes `\` and `"` explicitly and writes control characters other than tab, line feed and carriage return as `\uXXXX`.

### Required changes in consuming projects

- Turtle output no longer escapes apostrophes (`it's` instead of `it\'s`) and N-Triples/JSON-LD output no longer escapes slashes. Both are valid and equivalent; only byte-level comparisons of serialisations see a difference.
- Projects that referenced test classes of this extension by their old `Digicademy\Lod\Tests\…` names must use `Tests\…`.

### Verification

`vendor/bin/codecept run Unit -c packages/lod` in `1drop/php-utils:8.5`: 82 tests, 168 assertions, all passing after the two fixes (four failing before them, as expected). PHPStan against `phpstan.neon`: 11 errors, unchanged, none in the changed classes. The `Integration` suite could not be run in the sandbox: `Bootstrap::init()` starts, but aborts on a PHP 8.4 deprecation in `culture_portal`'s `SparqlQueryService` (see the application's upgrade history).

## 2026-10-09 — Implicitly nullable parameter in `StatementRepository::findByPosition()`

`2d22ba6`. `findByPosition()` declared `IriNamespace $graph = null`, implicitly nullable, which PHP 8.4 deprecates. Where `E_DEPRECATED` is in `SYS.exceptionalErrors`, the deprecation is thrown as soon as the class is compiled — for example during `Bootstrap::init()` in an `Integration` test run. The parameter is now `?IriNamespace`. No changes needed in consuming projects. A lint of `Classes/` with `E_ALL` reports no further deprecations; PHPStan goes from 11 errors to 10.

## 2026-10-09 — Correction: `FailOnPhpErrorsTrait` removed

The test entry above stated that PHPUnit only reports PHP warnings, and added `Tests/Support/FailOnPhpErrorsTrait.php` to turn them into exceptions. That was wrong: Codeception installs its own error handler, which throws for every error level in its `error_level` setting (default `E_ALL & ~E_DEPRECATED`), so a warning raised by the code under test already fails the test. The trait was redundant and has been removed; the tests that pin down warning-free behaviour call the code directly. Verified by running them against the code before `b445d68` and `6e90784`: the three affected tests still fail there, and all 82 pass on the current code.

## 2026-10-09 — `ItemMappingService` maps unresolvable references to no item

`mapItem()` and `mapGenericItem()` read `$result['row']` unguarded, but `load()` returns an empty array when it finds no row. Because `load()` queries through `Connection::select()`, which applies TYPO3's default restrictions, that is the case not only for missing records but also for deleted, hidden and expired ones. Reported as `PHP Warning: Undefined array key "row" … ItemMappingService.php line 63` on the NFDI4Culture registry page, where a search index listed deleted relations that were mapped through `mapItem()`. Both methods now return `null` for such a reference, which their `?object` / `?Record` return types already announced; `load()` documents the restrictions and its return shape.

### Required changes in consuming projects

None. Callers must already handle `null`; those that did not (`$item->…` on the result) failed for these references before, too.

### Verification

`php -l` clean; PHPStan: no error in the file; Unit suite 82 tests passing. In the NFDI4Culture portal, `/resources/registry.html` renders again (HTTP 200) while the index still lists the deleted relation.
