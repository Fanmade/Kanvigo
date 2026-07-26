@props(['doc', 'childrenByParent', 'shortName', 'depth' => 0])

{{--
    A doc and everything nested under it, rendered as a flat run of indented rows
    (so the enclosing x-list-card keeps its hairline separators). `childrenByParent`
    is the visible docs grouped by their parent's id.
--}}
<x-doc-row :doc="$doc" :short-name="$shortName" :depth="$depth" />

@foreach ($childrenByParent->get($doc->id, collect()) as $child)
    <x-doc-tree-item
        :doc="$child"
        :children-by-parent="$childrenByParent"
        :short-name="$shortName"
        :depth="$depth + 1"
    />
@endforeach
