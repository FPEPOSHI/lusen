{{-- A response body's fields, flattened to dotted paths so a reader scanning
     for one field does not have to unfold nested JSON to find it. --}}
@php($rows = \Lusen\Support\SchemaFields::flatten($schema))

@if ($rows)
    <div class="mt-2 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b border-slate-200 text-xs uppercase tracking-wider text-slate-500 dark:border-slate-800">
                    <th scope="col" class="py-2 pr-4 font-medium">Field</th>
                    <th scope="col" class="py-2 pr-4 font-medium">Type</th>
                    <th scope="col" class="py-2 font-medium">Description</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr class="border-b border-slate-100 last:border-0 dark:border-slate-800/60">
                        <td class="py-2 pr-4 font-mono text-slate-900 dark:text-white">{{ $row['name'] }}</td>
                        <td class="py-2 pr-4 text-slate-600 dark:text-slate-400">{{ $row['type'] }}</td>
                        <td class="py-2 text-slate-600 dark:text-slate-400">{{ $row['description'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
