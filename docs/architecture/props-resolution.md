---
type: Architecture View
title: Props resolution
description: How PropsResolver::resolve turns shared and page props into the props sent and the page metadata.
kind: flow
timestamp: 2026-10-28T16:31:33Z
---

# Props resolution

This view answers how `PropsResolver::resolve` turns the shared props and the page props into the props sent and the metadata written on the page object. The steps follow `resolve()`, `resolveProps()` and `resolveValue()` in `src/PropsResolver.php`.

```mermaid
flowchart TD
    In["resolve(shared, props)"] --> Prov["resolveSharedProps:<br/>expand ProvidesInertiaProperties,<br/>collect sharedProps keys when exposeSharedPropKeys is on"]
    Prov --> Merge["array_replace(shared, props):<br/>page props win"]
    Merge --> Dot["unpackDotProps:<br/>dotted keys become nested arrays"]
    Dot --> Walk["resolveProps: for each key, path = prefix.key"]

    Walk --> Part{"partial reload and<br/>not AlwaysProp?"}
    Part -->|"path not reached by only,<br/>or reached by except"| Skip["prop dropped"]
    Part -->|"reached, or AlwaysProp"| Full{"full render and<br/>excluded from first load?"}
    Full -->|"IgnoreFirstLoad: OptionalProp, DeferProp"| Ign["DeferProp is listed under deferredProps,<br/>with its merge and once metadata"]
    Ign --> Skip
    Full -->|"Deferrable that defers, such as ScrollProp"| Def["listed under deferredProps,<br/>with its merge metadata"]
    Def --> Skip
    Full -->|"Once prop the client already holds"| OnceMeta["record onceProps"]
    OnceMeta --> Skip
    Full -->|"included"| Val["resolveValue"]

    Val --> Call["call a callable object"]
    Call --> PIP["ProvidesInertiaProperty:<br/>toInertiaProperty(PropertyContext)"]
    PIP --> JS["JsonSerializable:<br/>jsonSerialize()"]
    JS --> Resc{"threw and Rescuable<br/>with shouldRescue()?"}
    Resc -->|"yes"| Rescued["log, record rescuedProps"]
    Rescued --> Skip
    Resc -->|"no throw"| Unwrap{"closure returned<br/>a prop type?"}
    Unwrap -->|"yes, once"| Walk2["re-run the full-render exclusion<br/>and resolve the unwrapped prop again"]
    Walk2 -->|"excluded from first load"| Skip
    Unwrap -->|"no"| Collect["collectMetadata:<br/>mergeProps, prependProps, deepMergeProps,<br/>matchPropsOn, scrollProps, onceProps"]
    Walk2 -->|"included"| Collect
    Collect --> Child{"value is an array?"}
    Child -->|"yes"| Walk
    Child -->|"no"| Out["value kept"]

    Out --> Meta["buildMetadata:<br/>sharedProps, mergeProps, prependProps, deepMergeProps,<br/>matchPropsOn, deferredProps, rescuedProps,<br/>scrollProps, onceProps"]
```

A partial reload counts only when `X-Inertia-Partial-Component` names the component being rendered. `AlwaysProp` bypasses the `only` and `except` filter, and so do the children of a value that was not an array before it was resolved. A rescued prop is skipped by the walk and listed under `rescuedProps`, except when a closure returned the rescuing prop: that key stays in the props as `null`. A throwable from any other prop propagates.

## Drift instrument

No deterministic instrument exists for this view; it is kept by inspection under the no-drift rule, and a change to the order of steps in `PropsResolver` or to a key of the page metadata updates it in the same commit. The behavior is exercised by `tests/PropsResolverTest.php`, `tests/InertiaFilterTest.php` and `tests/InertiaResponseTest.php`.
