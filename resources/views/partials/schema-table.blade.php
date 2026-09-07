{{--
    Fields, flattened to dotted paths and indented by depth.

    One partial for request parameters and response bodies, because they are
    the same question asked twice and used to answer it differently: the
    response table descended into nested objects and the request table did not,
    so a body with an `items` array documented as "array" and stopped, and
    nobody building the request could find out what went in it.

    The dotted path is the identity - `items[].product_id` is what a 422
    mentions, what a reader searches for, and what survives into the Markdown
    mirror where there is no colour to read.

    It is not indented. The path already says where a field lives, so an
    indent says it a second time, and pays for it twice: with the aligned left
    edge that is the only reason a column is scannable, and with width, in a
    reference column that shares a row with a 30rem example and has to fit a
    type like "string, one of pending, paid, shipped, refunded". Every docs
    site that indents drops the prefix and draws a guide line instead; doing
    both is the hybrid nobody ships.

    So the parent is muted and the leaf is not, which is the same thing the
    full URL line does with its host and its path - one idea, taught once.

    Callers pass `$rows` already flattened: `SchemaFields::forParameters()` for
    a parameter list, `SchemaFields::flatten()` for a schema.
--}}
@if ($rows)
    <div class="mt-2 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b border-slate-200 text-xs uppercase tracking-wider text-slate-500 dark:border-slate-800">
                    <th scope="col" class="py-2 pr-4 font-medium">{{ $nameHeading ?? 'Field' }}</th>
                    <th scope="col" class="py-2 pr-4 font-medium">Type</th>
                    @if ($showRequired ?? false)
                        <th scope="col" class="py-2 pr-4 font-medium">Required</th>
                    @endif
                    <th scope="col" class="py-2 font-medium">Description</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr class="border-b border-slate-100 last:border-0 dark:border-slate-800/60">
                        {{-- No whitespace between the spans: the two halves are
                             one path, and a newline in the markup would put a
                             space in the middle of what a reader copies. --}}
                        <td class="py-2 pr-4 font-mono"><span class="text-slate-500 dark:text-slate-400">{{ $row['parent'] }}</span><span class="text-slate-900 dark:text-white">{{ $row['leaf'] }}</span></td>
                        <td class="py-2 pr-4 text-slate-600 dark:text-slate-400">{{ $row['type'] }}</td>
                        @if ($showRequired ?? false)
                            <td class="py-2 pr-4 text-slate-600 dark:text-slate-400">{{ $row['required'] ? 'yes' : 'no' }}</td>
                        @endif
                        <td class="py-2 text-slate-600 dark:text-slate-400">{{ $row['description'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
