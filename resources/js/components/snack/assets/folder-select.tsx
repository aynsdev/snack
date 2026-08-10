import { useMemo } from 'react';
import { buildFolderTree } from '@/components/snack/assets/folder-tree';
import type { FolderNode } from '@/components/snack/assets/folder-tree';
import type { AssetFolder } from '@/types';

/**
 * "Where does this go?" — every shelf in the library, indented.
 *
 * A native `<select>` rather than the styled primitive, for the reason the file
 * browser's channel filter is one: it submits as part of a plain form, and a
 * component that has to mirror its value into a hidden input to do that is two
 * sources of truth for one answer.
 *
 * The indentation is non-breaking spaces because a `<select>` renders no markup
 * inside its options — this is the only way a tree can be seen in one, and it is
 * why the tree is flattened depth-first here rather than sorted by name.
 *
 * `exclude` is the folder being moved, when a folder is what is being moved: a
 * shelf cannot be filed inside itself or inside its own subtree, and offering
 * destinations the server is going to refuse is a validation error the person
 * could not have avoided.
 */
export function FolderSelect({
    id,
    name,
    folders,
    value,
    exclude = null,
}: {
    id: string;
    name: string;
    folders: AssetFolder[];
    value: number | null;
    /** A folder whose own subtree is not a legal destination. */
    exclude?: number | null;
}) {
    const options = useMemo(
        () => flatten(buildFolderTree(folders), 0, exclude),
        [folders, exclude],
    );

    return (
        <select
            id={id}
            name={name}
            defaultValue={value === null ? '' : String(value)}
            className="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
        >
            <option value="">Top of the library</option>

            {options.map(({ folder, depth }) => (
                <option key={folder.id} value={folder.id}>
                    {'  '.repeat(depth)}
                    {folder.name}
                </option>
            ))}
        </select>
    );
}

function flatten(
    nodes: FolderNode[],
    depth: number,
    exclude: number | null,
): { folder: FolderNode; depth: number }[] {
    return nodes.flatMap((node) =>
        node.id === exclude
            ? []
            : [
                  { folder: node, depth },
                  ...flatten(node.children, depth + 1, exclude),
              ],
    );
}
