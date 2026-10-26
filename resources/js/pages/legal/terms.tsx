import { Link } from '@inertiajs/react';
import { ContactLink, LEGAL, LegalPage } from '@/components/legal/legal-page';
import { deleteAccount, privacy } from '@/routes/legal';

export default function Terms() {
    return (
        <LegalPage
            title="Terms of Service"
            summary={`These terms govern your use of Snack, the team chat, project and HR workspace operated by ${LEGAL.company}, on the web and in the Snack mobile apps.`}
        >
            <section>
                <h2>1. Agreement</h2>
                <p>
                    By creating an account, accepting an invitation, or using
                    Snack you agree to these terms and to our{' '}
                    <Link href={privacy()}>Privacy Policy</Link>. If you use
                    Snack on behalf of an organization, you confirm you are
                    authorized to accept these terms for it. If you do not
                    agree, do not use Snack.
                </p>
            </section>

            <section>
                <h2>2. Accounts</h2>
                <ul>
                    <li>
                        You must provide accurate information and be at least 16
                        years old, or the age of digital consent where you live.
                    </li>
                    <li>
                        You are responsible for keeping your password and
                        devices secure and for everything done through your
                        account. Tell us promptly at <ContactLink /> if you
                        suspect unauthorized access.
                    </li>
                    <li>
                        One person, one account. Bot and integration accounts
                        are created by workspace administrators, not by signing
                        up.
                    </li>
                </ul>
            </section>

            <section>
                <h2>3. Workspaces</h2>
                <p>
                    Snack is organized into workspaces. The organization that
                    owns a workspace, through its administrators, decides who
                    may join, what members can see, and how long content is
                    kept. Workspace administrators can access, manage, export or
                    remove content posted in their workspace, including HR
                    records such as attendance, leave and employment documents.
                    Where a workspace is used by an employer, the employer is
                    responsible for its own obligations to its employees under
                    applicable labor and data privacy laws.
                </p>
            </section>

            <section>
                <h2>4. Your content</h2>
                <p>
                    You keep ownership of the messages, files, tasks and other
                    content you post. You grant {LEGAL.company} a limited
                    license to host, store, process, transmit and display that
                    content only as needed to operate Snack for you and your
                    workspace — for example, delivering a message, generating a
                    file preview, sending a push notification, or answering a
                    request you make to the Bite AI assistant.
                </p>
            </section>

            <section>
                <h2>5. Acceptable use</h2>
                <p>You agree not to use Snack to:</p>
                <ul>
                    <li>
                        Break the law, or infringe anyone's intellectual
                        property, privacy or other rights.
                    </li>
                    <li>
                        Harass, threaten, or abuse others, or post content that
                        is sexually exploitative of minors, hateful, or promotes
                        violence.
                    </li>
                    <li>
                        Send spam, malware, or phishing, or attempt to access
                        accounts, workspaces or systems you are not authorized
                        to access.
                    </li>
                    <li>
                        Interfere with, overload, scrape, or reverse engineer
                        the service, except where the law expressly allows it.
                    </li>
                </ul>
                <p>
                    Report abusive content or users to your workspace
                    administrator or to us at <ContactLink />. We may remove
                    content or suspend accounts that violate these terms.
                </p>
            </section>

            <section>
                <h2>6. AI features</h2>
                <p>
                    Bite, Snack's AI assistant, generates responses
                    automatically and can be wrong. Review its output before
                    relying on it, especially for HR, legal, or financial
                    decisions. Actions Bite proposes that change your workspace
                    require your approval.
                </p>
            </section>

            <section>
                <h2>7. Third-party integrations</h2>
                <p>
                    Workspaces can connect webhooks and AI tools such as MCP
                    clients. Your use of those services is governed by their own
                    terms, and we are not responsible for them.
                </p>
            </section>

            <section>
                <h2>8. Ending your use</h2>
                <p>
                    You can stop using Snack and{' '}
                    <Link href={deleteAccount()}>delete your account</Link> at
                    any time. We may suspend or terminate access if you breach
                    these terms or if required by law. Sections that by their
                    nature should survive termination will survive.
                </p>
            </section>

            <section>
                <h2>9. Disclaimers and liability</h2>
                <p>
                    Snack is provided "as is" and "as available". To the fullest
                    extent permitted by law, {LEGAL.company} disclaims all
                    implied warranties, and is not liable for indirect,
                    incidental, special, consequential or punitive damages, or
                    for loss of data, profits or goodwill. Our total liability
                    for any claim relating to Snack is limited to the amount you
                    paid us for Snack in the twelve months before the claim, or
                    PHP 5,000 if you paid nothing. Nothing in these terms limits
                    liability that cannot be limited by law.
                </p>
            </section>

            <section>
                <h2>10. Changes</h2>
                <p>
                    We may update these terms. If a change is material, we will
                    notify you in the app or by email before it takes effect.
                    Continuing to use Snack after that means you accept the
                    updated terms.
                </p>
            </section>

            <section>
                <h2>11. Governing law</h2>
                <p>
                    These terms are governed by the laws of {LEGAL.country}.
                    Disputes will be resolved in the courts of Metro Manila,
                    unless the law where you live gives you the right to bring
                    them elsewhere.
                </p>
            </section>

            <section>
                <h2>12. Contact</h2>
                <p>
                    Questions about these terms: <ContactLink />.
                </p>
            </section>
        </LegalPage>
    );
}
