import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ChevronDown, HelpCircle } from 'lucide-react';
import { useState } from 'react';

const faqs = [
  {
    question: 'What is OPBX?',
    answer:
      'OPBX is an open-source business PBX platform built on Cloudonix CPaaS. It handles call routing, IVR menus, ring groups, AI voice agents, auto dialer campaigns, call tracking, and business communications without requiring you to manage VoIP infrastructure.',
  },
  {
    question: 'How does OPBX work with Cloudonix?',
    answer:
      'OPBX integrates with Cloudonix to handle all VoIP and telephony operations. Cloudonix manages the actual call infrastructure, while OPBX provides the configuration interface and runtime routing decisions.',
  },
  {
    question: 'Is OPBX really free?',
    answer:
      'Yes! OPBX is fully open source under the MIT license. You can self-host it on your own infrastructure at no cost. You will need a Cloudonix account for telephony services, which has its own pricing structure.',
  },
  {
    question: 'What features are included?',
    answer:
      'OPBX includes multi-tenant organizations, user extensions, DID number mapping, inbound call routing (direct, ring groups, IVR, AI assistants), business hours routing, call logs, real-time call presence, AI voice agents, auto dialer, call tracking, AI load balancers, and a complete admin UI.',
  },
  {
    question: 'Can I use OPBX for outbound calling?',
    answer:
      'Yes. OPBX includes an Auto Dialer for outbound campaigns, distribution lists, and scheduling. Additional outbound features are planned for future releases.',
  },
  {
    question: 'How do I get started?',
    answer:
      'Sign up for an account, configure your organization and extensions, connect your Cloudonix account with API credentials, map your DID numbers, and start routing calls. The entire setup takes just minutes.',
  },
];

export function FAQSection() {
  const [openFaq, setOpenFaq] = useState<number | null>(null);

  return (
    <section id="faq" className="bg-muted/30 py-20 md:py-32">
      <div className="container mx-auto px-4">
        <div className="max-w-3xl mx-auto">
          <div className="text-center mb-16">
            <div className="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-primary/10 mb-6">
              <HelpCircle className="h-4 w-4 text-primary" />
              <span className="text-sm font-medium text-primary">FAQ</span>
            </div>
            <h2 className="text-4xl md:text-5xl font-bold mb-4">Frequently Asked Questions</h2>
            <p className="text-xl text-muted-foreground">Everything you need to know about OPBX</p>
          </div>
          <div className="space-y-4">
            {faqs.map((faq, idx) => (
              <Card
                key={idx}
                className="cursor-pointer transition-all hover:shadow-md"
                onClick={() => setOpenFaq(openFaq === idx ? null : idx)}
              >
                <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-4">
                  <CardTitle className="text-lg font-semibold pr-8">{faq.question}</CardTitle>
                  <ChevronDown
                    className={`h-5 w-5 text-muted-foreground transition-transform flex-shrink-0 ${
                      openFaq === idx ? 'rotate-180' : ''
                    }`}
                  />
                </CardHeader>
                {openFaq === idx && (
                  <CardContent className="pt-0">
                    <p className="text-muted-foreground leading-relaxed">{faq.answer}</p>
                  </CardContent>
                )}
              </Card>
            ))}
          </div>
        </div>
      </div>
    </section>
  );
}
