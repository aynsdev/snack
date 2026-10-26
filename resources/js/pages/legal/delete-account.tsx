import { ContactLink, LEGAL, LegalPage } from '@/components/legal/legal-page';

/**
 * The page the Play Store's "delete account URL" points at. Google requires it
 * to name the app, give the steps, and say what is deleted and what is kept —
 * all three are here, and the in-app steps must match the real settings screen.
 */
export default function DeleteAccount() {
    return (
        <LegalPage
            title="Delete your Snack account"
            summary={`How to permanently delete your Snack account, published by ${LEGAL.company}, and what happens to your data when you do.`}
        >
            <section>
                <h2>In the mobile app</h2>
                <ol>
                    <li>Open the Snack app and sign in.</li>
                    <li>Go to your profile, then Settings.</li>
                    <li>
                        Tap <strong>Delete account</strong>.
                    </li>
                    <li>Enter your password to confirm.</li>
                </ol>
            </section>

            <section>
                <h2>On the web</h2>
                <ol>
                    <li>Sign in to Snack in your browser.</li>
                    <li>
                        Open <strong>Settings → Profile</strong>.
                    </li>
                    <li>
                        Under <strong>Delete account</strong>, click{' '}
                        <strong>Delete account</strong> and enter your password
                        to confirm.
                    </li>
                </ol>
            </section>

            <section>
                <h2>If you can't sign in</h2>
                <p>
                    Email <ContactLink /> from the address on your account with
                    the subject "Delete my Snack account". We will verify that
                    the request came from you and delete the account within 30
                    days, and confirm by email when it is done.
                </p>
            </section>

            <section>
                <h2>What is deleted</h2>
                <p>Deletion is immediate and cannot be undone. We delete:</p>
                <ul>
                    <li>
                        Your profile: name, email, username, password and
                        profile photo.
                    </li>
                    <li>
                        Your workspace memberships, preferences, saved items and
                        registered push devices.
                    </li>
                    <li>Files you attached to messages.</li>
                    <li>Your Bite AI conversations.</li>
                    <li>
                        Your HR employee records, including attendance and
                        clock-in locations.
                    </li>
                </ul>
                <p>Your sign-in sessions and mobile app tokens stop working.</p>
            </section>

            <section>
                <h2>What is kept</h2>
                <ul>
                    <li>
                        The text of messages you posted in shared channels and
                        conversations stays visible to the other members,
                        without your name or photo, because it is part of the
                        workspace's shared history. Ask your workspace
                        administrator if you want specific messages removed
                        before you delete your account.
                    </li>
                    <li>
                        Files you added to a workspace's shared asset library,
                        and security audit log entries, no longer linked to you.
                    </li>
                    <li>
                        Copies in encrypted backups, which are overwritten
                        within 30 days.
                    </li>
                </ul>
            </section>

            <section>
                <h2>Workspace owners</h2>
                <p>
                    A workspace's owner can't delete their account while they
                    still own it. Contact us at <ContactLink /> and we will
                    either transfer ownership to another member or delete the
                    workspace and all of its content.
                </p>
            </section>
        </LegalPage>
    );
}
