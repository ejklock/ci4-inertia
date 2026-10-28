---
type: Architecture View
title: Module layout
description: The modules of src/ and the dependencies between them.
kind: component
timestamp: 2026-10-28T16:31:33Z
---

# Module layout

This view answers how `src/` divides into modules and which module depends on which, as the `use` statements show. The core sits at the root namespace `Ejklock\Ci4Inertia`; the other modules are its sub-namespaces and `src/Helpers`, which holds the global helper functions. Prop types and their interfaces share the root namespace and are drawn as their own groups to keep the core readable.

```mermaid
flowchart TD
    subgraph Root["Ejklock\\Ci4Inertia (root namespace)"]
        Core["Core<br/>Inertia, ResponseFactory,<br/>InertiaResponse, PropsResolver"]
        PropTypes["Prop types<br/>AlwaysProp, OptionalProp, DeferProp,<br/>MergeProp, OnceProp, ScrollProp"]
        Interfaces["Prop interfaces<br/>Deferrable, Mergeable, Onceable, IgnoreFirstLoad,<br/>Rescuable, ProvidesInertiaProperty,<br/>ProvidesInertiaProperties, ProvidesScrollMetadata"]
        Traits["Shared traits<br/>MergesProps, ResolvesOnce,<br/>ResolvesCallables, PreservesBigIntegers"]
        Errors["Exception rendering<br/>InertiaExceptionHandler, ExceptionResponse"]
    end

    Filters["Filters<br/>InertiaFilter, InertiaCsrfFilter,<br/>XsrfTokenCookieFilter, ..."]
    Ssr["Ssr<br/>Gateway, HttpGateway, SsrState,<br/>BundleDetector"]
    Commands["Commands<br/>StartSsr, StopSsr, CheckSsr"]
    Support["Support<br/>Header, SessionKey, SiteOrigin"]
    Testing["Testing<br/>AssertableInertia, InertiaTestTrait"]
    Helpers["Helpers<br/>inertia(), inertia_app(), inertia_head()"]
    Config["Config<br/>Inertia, Services"]

    Core --> PropTypes
    Core --> Interfaces
    PropTypes --> Interfaces
    PropTypes --> Traits
    Core --> Support
    Core --> Ssr
    Core --> Config
    Errors --> Filters
    Filters --> Core
    Filters --> Support
    Filters --> Ssr
    Helpers --> Core
    Ssr --> Config
    Ssr --> Traits
    Commands --> Ssr
    Commands --> Config
    Config --> Core
    Config --> Ssr
    Testing --> Support
    Testing --> Core
```

`Core` reaches `Ssr` through the `ConfiguresSsrRequests`, `DisablesSsr` and `ExcludesSsrPaths` interfaces, and reaches `Config` through `Config\Services` to find the shared factory. `Config` and `Core` depend on each other: `Services` builds the `ResponseFactory`, and `Inertia` and `InertiaResponse` ask `Services` for the shared instance. `Ssr` and `Commands` use `Config\Inertia` for the SSR options. Nothing in the package depends on the application namespace `App`.

## Drift instrument

`tests/Architecture/PackageDoesNotDependOnAppTest.php` (phpat, run with the PHPStan analysis) fails when a class under `Ejklock\Ci4Inertia` depends on a class under `App`, so that arrow cannot be drawn and cannot appear in the code. The arrows inside the package have no deterministic check; they are kept by inspection, and a new `use` between modules updates this view in the same commit.
