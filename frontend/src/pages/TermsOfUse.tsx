import { HomeHeader } from './Home/HomeHeader';
import { Footer } from './Home/Footer';

export const TERMS_UPDATED = 'November 15, 2026';
const REPO = 'https://github.com/greenfieldtech-nirs/OPBX';

/** A paragraph, a numbered sub-clause ({ lead, text }), a bullet list or a list of definitions. */
type Block = string | { lead: string; text: string } | { list: string[] } | { defs: [term: string, meaning: string][] };
type Section = { id: string; title: string; body: Block[] };

export const SECTIONS: Section[] = [
  {
    id: 'acceptance',
    title: 'Acceptance of these Terms',
    body: [
      'These Terms of Use (the "Terms") govern your access to and use of the Cloud Application and the Software (each as defined below). By accessing or using the Cloud Application, or by downloading, installing, copying, modifying, deploying or otherwise using the Software, you agree to be bound by these Terms. If you do not agree, you must not access or use the Cloud Application or the Software.',
      'If you accept these Terms on behalf of a company or other legal entity, you represent that you have the authority to bind that entity, and "you" refers to that entity.',
    ],
  },
  {
    id: 'definitions',
    title: 'Definitions',
    body: [
      {
        defs: [
          ['"OPBX Project"', 'the open-source project that creates and publishes OPBX, including its creators, maintainers and contributors.'],
          ['"Cloudonix"', 'Cloudonix and its affiliates, and their respective officers, directors, employees and agents.'],
          ['"Related Parties"', 'the OPBX Project, Cloudonix, and each of their respective creators, maintainers, contributors, affiliates, licensors, service providers and any other third party related to any of them, whether directly or indirectly.'],
          ['"Cloud Application"', 'any instance of OPBX that is hosted or operated by or on behalf of any Related Party and made available online, including the service known as OPBX Cloud.'],
          ['"Software"', 'the OPBX source code, executables, container images, scripts, documentation and any other materials published by the OPBX Project, in whole or in part, including any copy or modification of them.'],
          ['"You"', 'the person or entity that accesses or uses the Cloud Application or the Software.'],
        ],
      },
    ],
  },
  {
    id: 'cloud',
    title: 'The Cloud Application',
    body: [
      { lead: '3.1 Demonstration only.', text: 'The Cloud Application is provided solely for demonstration and evaluation purposes. It is not intended for, and must not be used or relied upon for, any production, commercial or business-critical purpose.' },
      { lead: '3.2 No availability commitment.', text: 'No service level agreement (SLA) applies. The Cloud Application may be modified, suspended, interrupted, reset or discontinued, in whole or in part, at any time and for any reason, including for upgrades and updates, without prior notice and without liability to you.' },
      { lead: '3.3 Data.', text: 'Any data you submit to the Cloud Application, including accounts, settings, recordings and call records, may be lost, deleted or reset at any time without notice. No backups are kept for you. Do not submit confidential or sensitive information, or personal data that you are not entitled to share or cannot afford to lose.' },
      { lead: '3.4 No emergency use.', text: 'The Cloud Application must not be used to place or receive emergency calls, or for any communication on which the life, health, safety or property of any person depends.' },
      { lead: '3.5 Accounts.', text: 'You are responsible for keeping your credentials confidential and for all activity under your account. Any account may be suspended or deleted at any time without notice.' },
    ],
  },
  {
    id: 'software',
    title: 'The Open-Source Software',
    body: [
      { lead: '4.1 License.', text: 'The Software is licensed under the MIT License. Your rights to use, copy, modify, merge, publish, distribute, sublicense and sell copies of the Software are granted by that license, and nothing in these Terms limits them. These Terms apply in addition to the MIT License, including its disclaimer of warranties and limitation of liability.' },
      { lead: '4.2 Your deployments.', text: 'If you install, configure, operate, modify or distribute the Software, or provide any service to others with it, you do so entirely on your own. You alone are responsible for its installation, configuration, security, updates, backups, availability and legal compliance, and for any service you provide with it.' },
    ],
  },
  {
    id: 'responsibility',
    title: 'Your Responsibility',
    body: [
      'Any use of the Cloud Application or the Software is at your sole risk and is your sole responsibility. Without limiting the foregoing, you are solely responsible for:',
      {
        list: [
          'complying with all laws and regulations that apply to you and to your use, including those on telecommunications, call recording and consent, telemarketing and automated dialing, privacy and data protection, and export control;',
          'obtaining every consent, notice and authorization required from the people whose calls, recordings or personal data you process;',
          'all charges you incur with any carrier or service provider, including charges resulting from unauthorized use or toll fraud;',
          'the security of your accounts, credentials, systems and data; and',
          'any content, data or communications you send, store or process.',
        ],
      },
      'You must not use the Cloud Application or the Software to break the law, infringe the rights of others, send unsolicited communications, commit fraud, or attack, probe, overload or gain unauthorized access to any system, including the Cloud Application.',
    ],
  },
  {
    id: 'third-parties',
    title: 'Third-Party Services',
    body: [
      'The Cloud Application and the Software can connect to services provided by third parties, such as communications platforms (including the Cloudonix platform), telephone carriers, email providers, identity providers and artificial-intelligence services. Your use of those services is governed solely by the terms between you and their providers. The Related Parties do not control, and are not responsible or liable for, any third-party service, its availability, its charges or its handling of your data.',
    ],
  },
  {
    id: 'warranties',
    title: 'Disclaimer of Warranties',
    body: [
      'THE CLOUD APPLICATION AND THE SOFTWARE ARE PROVIDED "AS IS" AND "AS AVAILABLE", WITH ALL FAULTS AND WITHOUT WARRANTY OF ANY KIND. TO THE MAXIMUM EXTENT PERMITTED BY APPLICABLE LAW, THE RELATED PARTIES DISCLAIM ALL WARRANTIES, WHETHER EXPRESS, IMPLIED, STATUTORY OR OTHERWISE, INCLUDING ANY WARRANTY OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE, TITLE, NON-INFRINGEMENT, ACCURACY, SECURITY, AVAILABILITY, AND UNINTERRUPTED OR ERROR-FREE OPERATION.',
      'NO ADVICE OR INFORMATION, WHETHER ORAL OR WRITTEN, OBTAINED FROM ANY RELATED PARTY OR THROUGH THE CLOUD APPLICATION, THE SOFTWARE OR ITS DOCUMENTATION, CREATES ANY WARRANTY NOT EXPRESSLY STATED IN THESE TERMS.',
    ],
  },
  {
    id: 'liability',
    title: 'Limitation of Liability',
    body: [
      'TO THE MAXIMUM EXTENT PERMITTED BY APPLICABLE LAW, IN NO EVENT SHALL ANY RELATED PARTY BE LIABLE TO YOU OR TO ANY THIRD PARTY FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, CONSEQUENTIAL, EXEMPLARY OR PUNITIVE DAMAGES, OR FOR ANY LOSS OF PROFITS, REVENUE, DATA, GOODWILL OR BUSINESS OPPORTUNITY, BUSINESS INTERRUPTION, CALL CHARGES OR TOLL FRAUD, ARISING OUT OF OR IN CONNECTION WITH THE CLOUD APPLICATION, THE SOFTWARE OR THESE TERMS, HOWEVER CAUSED AND UNDER ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, TORT (INCLUDING NEGLIGENCE), STRICT LIABILITY OR OTHERWISE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGES.',
      'YOUR SOLE AND EXCLUSIVE REMEDY FOR ANY DISSATISFACTION WITH THE CLOUD APPLICATION OR THE SOFTWARE IS TO STOP USING THEM.',
      'Some jurisdictions do not allow the exclusion or limitation of certain warranties or damages. In those jurisdictions, the exclusions and limitations above apply to the fullest extent permitted by law.',
    ],
  },
  {
    id: 'indemnification',
    title: 'Indemnification',
    body: [
      'You agree to defend, indemnify and hold harmless the Related Parties from and against any claim, demand, loss, damage, liability, cost or expense (including reasonable legal fees) arising out of or related to: (a) your access to or use of the Cloud Application or the Software; (b) any deployment, modification or distribution of the Software by you, or any service you provide with it; (c) any content or data you send, store or process; (d) your violation of these Terms or of any law; or (e) your violation of any right of a third party.',
    ],
  },
  {
    id: 'changes',
    title: 'Changes and Termination',
    body: [
      'These Terms may be updated at any time. The "Last updated" date at the top of this page shows when they last changed. Your continued use of the Cloud Application or the Software after a change means you accept the updated Terms.',
      'Your access to the Cloud Application may be suspended or terminated at any time, for any reason, without notice. The sections on Your Responsibility, Third-Party Services, Disclaimer of Warranties, Limitation of Liability, Indemnification and General survive any termination.',
    ],
  },
  {
    id: 'general',
    title: 'General',
    body: [
      'These Terms, together with the MIT License for the Software, are the entire agreement between you and the Related Parties about their subject matter. If any provision is held invalid or unenforceable, it will be enforced to the maximum extent permissible and the remaining provisions remain in full force. A failure or delay in enforcing any provision is not a waiver of it. You may not assign or transfer these Terms without prior written consent; the Related Parties may assign them without restriction. Headings are for convenience only.',
    ],
  },
  {
    id: 'contact',
    title: 'Contact',
    body: [
      'Questions about these Terms can be raised by opening an issue in the OPBX GitHub repository.',
    ],
  },
];

function BlockView({ block }: { block: Block }) {
  if (typeof block === 'string') {
    // all-caps paragraphs are the conspicuous disclaimers: keep them readable
    return <p className={block === block.toUpperCase() ? 'text-sm tracking-wide text-foreground' : undefined}>{block}</p>;
  }
  if ('lead' in block) return <p><span className="font-semibold text-foreground">{block.lead}</span> {block.text}</p>;
  if ('list' in block) {
    return <ul className="list-disc space-y-1 pl-6">{block.list.map((item) => <li key={item}>{item}</li>)}</ul>;
  }
  return (
    <dl className="space-y-2">
      {block.defs.map(([term, meaning]) => (
        <div key={term}>
          <dt className="inline font-semibold text-foreground">{term}</dt>
          <dd className="inline"> means {meaning}</dd>
        </div>
      ))}
    </dl>
  );
}

export default function TermsOfUse() {
  return (
    <div className="min-h-screen bg-background text-foreground home-dark oranienbaum-regular flex flex-col">
      <HomeHeader />
      <main className="container mx-auto px-4 py-12 flex-1">
        <header className="mb-10 border-b border-border pb-6">
          <h1 className="text-4xl font-bold mb-2">Terms of Use</h1>
          <p className="text-muted-foreground">OPBX Cloud and the OPBX open-source software · Last updated {TERMS_UPDATED}</p>
        </header>

        <div className="grid gap-10 lg:grid-cols-[16rem_minmax(0,1fr)]">
          <nav aria-label="Sections" className="lg:sticky lg:top-24 lg:self-start">
            <p className="mb-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Contents</p>
            <ol className="space-y-1 text-sm">
              {SECTIONS.map((s, i) => (
                <li key={s.id}>
                  <a href={`#${s.id}`} className="text-muted-foreground hover:text-foreground">{i + 1}. {s.title}</a>
                </li>
              ))}
            </ol>
          </nav>

          <article className="max-w-3xl space-y-10 leading-relaxed text-muted-foreground">
            <p className="rounded-lg border border-border bg-card p-4 text-sm">
              Please read these Terms carefully. They state that the Cloud Application and the Software are provided without
              any warranty, that their use is entirely at your own risk and responsibility, and that the OPBX Project,
              Cloudonix and all related parties are not liable for any use of them.
            </p>
            {SECTIONS.map((s, i) => (
              <section key={s.id} id={s.id} aria-labelledby={`${s.id}-title`} className="scroll-mt-24 space-y-3">
                <h2 id={`${s.id}-title`} className="text-xl font-bold text-foreground">{i + 1}. {s.title}</h2>
                {s.body.map((b, j) => <BlockView key={j} block={b} />)}
              </section>
            ))}
            <p className="text-sm">
              <a href={`${REPO}/issues`} target="_blank" rel="noopener noreferrer" className="underline hover:text-foreground">Open an issue on GitHub</a>
              {' · '}
              <a href={`${REPO}/blob/main/LICENSE.md`} target="_blank" rel="noopener noreferrer" className="underline hover:text-foreground">MIT License</a>
            </p>
          </article>
        </div>
      </main>
      <Footer />
    </div>
  );
}
