# Refactoring: Replace HTTP self-request in serialisation flow

## Problem

The serialisation templates (RDFXML, JSONLD, NT, TTL) use `<lod:GetUrl>` to make an HTTP request back to the same application to fetch the HTML/RDFa representation of a resource. This self-request triggers a second full TYPO3 bootstrap, duplicates the database queries, and introduces fragile dependencies on SSL configuration, DNS resolution, and network connectivity.

## Current flow

When a request hits e.g. `/resource/E1.rdf` (pageType 2004):

1. `ApiController::aboutAction()` fetches the `Iri` resource from the database
2. `showAction()` assigns `{resource}`, `{graph}`, `{iriNamespaces}` to the view
3. The RDFXML template renders `Show.rdfxml`, which calls:
   ```
   <lod:EasyRdfConverter inputFormat="rdfa" outputFormat="rdfxml">
       <lod:GetUrl url="{n4c:IriLink(iri: resource, environment: environment, pageType: 1991, skipArguments: 1)}"/>
   </lod:EasyRdfConverter>
   ```
4. `GetUrl` makes an HTTP request to `https://nfdi4culture.local/resource/E1/about.html`
5. This triggers a **second** `ApiController::aboutAction()` call, fetching the **same** data
6. The HTML template renders the resource with RDFa attributes
7. `EasyRdfConverter` parses the RDFa from the returned HTML and serialises to the target format

All four serialisation formats (RDFXML, JSONLD, NT, TTL) follow this pattern.

## Redundancy

Both the outer and inner requests execute the same controller, query the same data, and assign the same template variables. The only difference is the template that is rendered (serialisation format vs. HTML/RDFa).

## Options

### Option A: Render the HTML/RDFa partial internally (recommended)

Replace `<lod:GetUrl>` with a new ViewHelper that renders the HTML Show partial using a standalone Fluid view, without an HTTP request.

All required data (`resource`, `graph`, `iriNamespaces`, `environment`, `settings`) is already available in the current rendering context during serialisation.

The new ViewHelper would:

1. Create a standalone Fluid rendering context (`RenderingContextFactory` in TYPO3 12)
2. Configure it with the **HTML template paths** (the ones from TypoScript priorities 20 and 30: `EXT:culture_portal/Resources/Private/HTML/culture_portal/` and `EXT:culture_portal/Resources/Private/HTML/lod/`)
3. Assign the same template variables already present in the serialisation context
4. Render the `Api/Show` partial and return the HTML string

The serialisation templates would change from:
```
<lod:GetUrl url="{n4c:IriLink(iri: resource, environment: environment, pageType: 1991, skipArguments: 1)}"/>
```
to something like:
```
<lod:RenderRdfa partial="Api/Show" arguments="{_all}" templatePaths="{...}"/>
```

**Benefits:**
- Eliminates the HTTP round-trip, the second TYPO3 bootstrap, and duplicate DB queries
- Removes all SSL/network/DNS issues tied to the self-request
- Keeps the HTML/RDFa templates as the single source of truth for the RDF mapping

**Challenges:**
- The HTML template paths are configured via TypoScript (`plugin.tx_lod.view.*`) and swapped by conditions on `getPageType()`. The ViewHelper needs the paths for pageType 1991 (HTML) while rendering in a different pageType context (e.g. 2004 for RDFXML). This requires either passing the HTML paths as arguments/settings, or reading the base TypoScript config before the pageType conditions apply.

### Option B: Build EasyRdf Graph directly from the domain model

Skip HTML/RDFa entirely. Create a service or ViewHelper that programmatically builds an `EasyRdf\Graph` from the `Iri` domain object:

```php
$graph = new Graph();
foreach ($resource->getStatements() as $statement) {
    $graph->addLiteral(...); // or $graph->addResource(...)
}
return $graph->serialise($outputFormat);
```

**Benefits:**
- Most efficient: no intermediate HTML, no parsing, no double rendering
- Direct model-to-RDF conversion

**Challenges:**
- The `culture_portal` HTML templates contain extensive domain-specific RDFa logic across ~30 Record partials (`Record/Persons.html`, `Record/Units.html`, `Record/Products.html`, etc.) that map Extbase domain objects to RDF predicates. All of that mapping logic would need to be replicated in PHP.
- Creates a second source of truth for the RDF mapping, risking divergence between the HTML/RDFa output and the serialised formats.
