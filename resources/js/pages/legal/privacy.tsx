import { Link } from '@inertiajs/react';
import { ContactLink, LEGAL, LegalPage } from '@/components/legal/legal-page';
import { deleteAccount, terms } from '@/routes/legal';

type DataSafetyRow = {
    category: string;
    collected: string;
    purpose: string;
};

/**
 * The App Store privacy label and Play Store Data Safety form, in the words
 * those forms use. Keep it in step with what the code actually collects — a
 * store reviewer compares this table against the listing.
 */
const DATA_SAFETY: readonly DataSafetyRow[] = [
    {
        category: 'Personal info',
        collected: 'Name, email address, username, profile photo',
        purpose: 'Account management, app functionality',
    },
    {
        category: 'Messages',
        collected: 'Channel and direct messages, threads, reactions, mentions',
        purpose: 'App functionality',
    },
    {
        category: 'Photos, videos and files',
        collected: 'Images, documents and other files you upload',
        purpose: 'App functionality',
    },
    {
        category: 'Audio and video',
        collected:
            'Live microphone and camera streams during huddles (not recorded)',
        purpose: 'App functionality',
    },
    {
        category: 'Location',
        collected:
            'Precise location at HR clock-in and clock-out, only when you clock in or out',
        purpose: 'App functionality (attendance)',
    },
    {
        category: 'Employment info',
        collected:
            'HR records your workspace keeps: attendance, leave, shifts, contracts, documents',
        purpose: 'App functionality',
    },
    {
        category: 'App activity',
        collected:
            'Presence (online/away), read state, notification preferences, audit logs of workspace changes',
        purpose: 'App functionality, security',
    },
    {
        category: 'Device identifiers',
        collected: 'Push notification token for your device',
        purpose: 'Sending notifications',
    },
] as const;

export default function Privacy() {
    return (
        <LegalPage
            title="Privacy Policy"
            summary={`How ${LEGAL.company} collects, uses, shares and protects your information when you use Snack on the web and in the Snack mobile apps.`}
        >
            <section>
                <h2>1. Who we are</h2>
                <p>
                    Snack is operated by {LEGAL.company}, based in{' '}
                    {LEGAL.country}. We process personal data in line with the
                    Philippine Data Privacy Act of 2012 (Republic Act No. 10173)
                    and other laws that apply to you. For workspaces run by an
                    employer or other organization, that organization decides
                    what data its workspace holds and acts as the controller of
                    it; we process it on their behalf.
                </p>
                <p>
                    Contact our Data Protection Officer at <ContactLink />.
                </p>
            </section>

            <section>
                <h2>2. Information we collect</h2>
                <h3>Information you give us</h3>
                <ul>
                    <li>
                        <strong>Account details:</strong> name, email address,
                        username, password (stored only as a one-way hash),
                        profile photo and status.
                    </li>
                    <li>
                        <strong>Content:</strong> messages, threads, reactions,
                        files, canvases, project tasks and comments, standup
                        answers, and anything else you post.
                    </li>
                    <li>
                        <strong>HR information:</strong> if your workspace uses
                        HR features — attendance and time entries, leave and
                        absence requests, shifts, contracts, employment history,
                        equipment assignments, documents and policy
                        acknowledgements.
                    </li>
                    <li>
                        <strong>Messages to Bite:</strong> what you ask the AI
                        assistant, and the workspace context it reads to answer.
                    </li>
                </ul>

                <h3>Information collected when you use Snack</h3>
                <ul>
                    <li>
                        <strong>Location:</strong> when you clock in or out,
                        with your permission, your device's precise location is
                        recorded with that entry. Snack does not track your
                        location at any other time.
                    </li>
                    <li>
                        <strong>Microphone and camera:</strong> used only while
                        you are in a huddle, with your permission, to stream
                        audio and video to other participants. Huddles are not
                        recorded.
                    </li>
                    <li>
                        <strong>Photos and files:</strong> only the ones you
                        choose to upload.
                    </li>
                    <li>
                        <strong>Device and usage data:</strong> push
                        notification token, presence, read state, IP address,
                        browser or app version, and security logs.
                    </li>
                </ul>
                <p>
                    Snack does not use advertising identifiers, does not show
                    ads, and does not use third-party analytics or tracking
                    SDKs.
                </p>
            </section>

            <section>
                <h2>3. How we use information</h2>
                <ul>
                    <li>
                        To provide Snack: deliver messages, store files, run
                        projects, HR and huddles.
                    </li>
                    <li>To send notifications by push and email.</li>
                    <li>To answer requests you make to Bite.</li>
                    <li>
                        To keep accounts and workspaces secure, prevent abuse,
                        and fix problems.
                    </li>
                    <li>To meet legal obligations.</li>
                </ul>
                <p>
                    We do not sell your personal data, and we do not use your
                    content to train AI models.
                </p>
            </section>

            <section>
                <h2>4. Who we share it with</h2>
                <p>
                    Your workspace's members and administrators see what you
                    post in that workspace, according to its channel and
                    permission settings. Beyond that, we share data only with
                    service providers that help us run Snack, under contracts
                    that limit their use of it:
                </p>
                <ul>
                    <li>
                        <strong>Cloud hosting and file storage</strong> (e.g.
                        DigitalOcean, Amazon Web Services) — servers, databases
                        and uploaded files.
                    </li>
                    <li>
                        <strong>AI provider</strong> (Anthropic) — processes
                        Bite requests. Data sent through the API is not used to
                        train their models.
                    </li>
                    <li>
                        <strong>Email delivery</strong> (Resend) — account and
                        notification emails.
                    </li>
                    <li>
                        <strong>Push notifications</strong> (Expo, Google
                        Firebase Cloud Messaging, Apple Push Notification
                        service) — delivers notifications to your device.
                    </li>
                    <li>
                        <strong>Real-time audio and video</strong> (LiveKit) —
                        relays huddle streams.
                    </li>
                </ul>
                <p>
                    We may also disclose data when required by law, to protect
                    the rights and safety of people, or as part of a merger or
                    acquisition, with notice to you.
                </p>
            </section>

            <section id="data-safety">
                <h2>5. Data safety summary</h2>
                <p>
                    This is the summary we provide for the App Store privacy
                    label and the Google Play Data Safety section. All data
                    below is linked to your account, none is used for tracking
                    or advertising, and none is sold.
                </p>

                <div className="mt-4 overflow-x-auto rounded-lg border border-border">
                    <table className="w-full text-left text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-medium"
                                >
                                    Data type
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-medium"
                                >
                                    What is collected
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-medium"
                                >
                                    Purpose
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-border">
                            {DATA_SAFETY.map((row) => (
                                <tr key={row.category}>
                                    <th
                                        scope="row"
                                        className="px-4 py-3 align-top font-medium"
                                    >
                                        {row.category}
                                    </th>
                                    <td className="px-4 py-3 align-top">
                                        {row.collected}
                                    </td>
                                    <td className="px-4 py-3 align-top text-muted-foreground">
                                        {row.purpose}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <ul>
                    <li>
                        <strong>Encrypted in transit:</strong> yes — all traffic
                        uses HTTPS/TLS.
                    </li>
                    <li>
                        <strong>You can request deletion:</strong> yes — see{' '}
                        <Link href={deleteAccount()}>
                            how to delete your account
                        </Link>
                        .
                    </li>
                    <li>
                        <strong>Optional permissions:</strong> location,
                        microphone, camera, photos and notifications are each
                        requested only when you use the feature that needs them,
                        and can be turned off in your device settings.
                    </li>
                </ul>
            </section>

            <section>
                <h2>6. Retention</h2>
                <p>
                    We keep your account data while your account exists.
                    Workspace content is kept for as long as the workspace keeps
                    it. When you delete your account, we delete your personal
                    data as described on the{' '}
                    <Link href={deleteAccount()}>account deletion page</Link>,
                    except where we must keep it to meet legal obligations,
                    resolve disputes, or enforce our agreements. Backups are
                    overwritten within 30 days.
                </p>
            </section>

            <section>
                <h2>7. Security</h2>
                <p>
                    Passwords are hashed, traffic is encrypted, access to
                    production systems is restricted, and workspaces are
                    isolated from one another. Two-factor authentication is
                    available for your account. No system is perfectly secure;
                    if a breach affects you, we will notify you and the National
                    Privacy Commission as the law requires.
                </p>
            </section>

            <section>
                <h2>8. Your rights</h2>
                <p>
                    Subject to applicable law, you have the right to be
                    informed, to access, correct, and delete your data, to
                    object to or restrict processing, to data portability, and
                    to lodge a complaint with the National Privacy Commission of
                    the Philippines (privacy.gov.ph) or your local authority.
                    You can edit your profile in Settings at any time; for
                    anything else, email <ContactLink />. For data held in an
                    employer's workspace, we may refer your request to that
                    workspace's administrators.
                </p>
            </section>

            <section>
                <h2>9. Children</h2>
                <p>
                    Snack is a workplace tool and is not directed to children
                    under 16. We do not knowingly collect their data; if you
                    believe a child has created an account, contact us and we
                    will delete it.
                </p>
            </section>

            <section>
                <h2>10. International transfers</h2>
                <p>
                    Our service providers may process data outside{' '}
                    {LEGAL.country}, including in Singapore and the United
                    States. We rely on contractual safeguards to protect it
                    wherever it is processed.
                </p>
            </section>

            <section>
                <h2>11. Changes</h2>
                <p>
                    We will post updates here and change the effective date. If
                    a change is material, we will notify you in the app or by
                    email. See also our{' '}
                    <Link href={terms()}>Terms of Service</Link>.
                </p>
            </section>
        </LegalPage>
    );
}
