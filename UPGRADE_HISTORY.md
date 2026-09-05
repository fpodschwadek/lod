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
