import type { SnackUser } from '@/types/snack';

export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
    /**
     * The workspace-facing shape of the signed-in user (avatar, presence,
     * handle), mirroring App\Http\Resources\UserResource. Null when signed out.
     *
     * `user` above is the raw model the starter kit's settings pages rely on;
     * prefer `profile` anywhere inside a workspace.
     */
    profile: SnackUser | null;
};

/* @chisel-passkeys */
export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};
/* @end-chisel-passkeys */

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
