---
type: Architecture View
title: Page visit
description: One page visit from InertiaFilter to the response, with the first-visit and Inertia XHR branches.
kind: sequence
timestamp: 2026-10-28T16:31:33Z
---

# Page visit

This view answers how one visit executes, in order, from the filter through the controller to the response, and where the first visit and an Inertia XHR visit part ways. The order is the one in `InertiaFilter::before`, `ResponseFactory::render` and `InertiaResponse::toResponse`.

```mermaid
sequenceDiagram
    participant B as Browser
    participant F as InertiaFilter
    participant C as Controller
    participant RF as ResponseFactory
    participant IR as InertiaResponse
    participant PR as PropsResolver
    participant V as Root view
    participant G as HttpGateway

    B->>F: request
    F->>F: before(): install version resolver, share(), excludeSsrPaths()
    alt X-Inertia GET and X-Inertia-Version differs from version()
        F-->>B: 409 with X-Inertia-Location, Vary: X-Inertia
    else version matches, or not an Inertia GET
        F->>C: continue to controller
        C->>RF: inertia(component, props) then render()
        RF->>RF: componentName(), ensureComponentExists() via PageFinder
        RF-->>C: InertiaResponse
        C->>IR: toResponse(request, response)
        IR->>PR: resolve(shared, props)
        Note over PR: partial reload: only X-Inertia-Partial-Data or<br/>X-Inertia-Partial-Except paths for X-Inertia-Partial-Component
        PR-->>IR: props and metadata
        alt request has X-Inertia (XHR visit)
            IR-->>C: asJson(): 200, X-Inertia: true, page object as JSON
        else first visit
            IR->>V: asHtml(): view(rootView, page)
            V->>G: inertia_app(page) via SsrState.dispatch()
            opt SSR enabled and bundle or hot file present
                G-->>V: Response with head and body
            end
            V-->>IR: SSR body, or script data-page and mount div
            IR-->>C: HTML response
        end
        C-->>F: response
        F->>F: after(): redirect rules, Vary: X-Inertia
        F-->>B: response
    end
```

On a first visit the root view prints the page through `inertia_app()`, which asks `SsrState` to dispatch the page to `HttpGateway`; when the gateway returns nothing, the helper prints the `script[data-page]` tag and the mount element. On an Inertia XHR visit no view is rendered. The `409` branch short-circuits in `before()`, so the controller never runs; a redirect to a URL with a `#fragment` produces a second kind of `409` in `after()`, which this view leaves out.

## Drift instrument

No deterministic instrument exists for this view; it is kept by inspection under the no-drift rule, and a change to the order of `before()`, `render()`, `toResponse()` or the root view hand-off updates it in the same commit. The behavior of each branch is exercised by `tests/InertiaFilterTest.php` and `tests/InertiaResponseTest.php`.
