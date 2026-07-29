import type { SVGAttributes } from 'react';
import { SnackLogoIcon } from '@/components/snack/snack-logo';

/**
 * Kept as the starter kit's entry point for the app mark so the auth layouts
 * and sidebar pick up Snack branding without each importing the brand
 * component directly.
 *
 * @see SnackLogoIcon for the mark itself.
 */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return <SnackLogoIcon {...props} />;
}
