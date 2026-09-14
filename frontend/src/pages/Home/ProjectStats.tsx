import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { BarChart3 } from 'lucide-react';

// ponytail: placeholder content — swap with real stats when supplied.
const stats = [
  {
    metric: '1,234',
    label: 'Lorem Ipsum',
    description: 'Lorem ipsum dolor sit amet, consectetur adipiscing elit.',
  },
  {
    metric: '5,678',
    label: 'Dolor Sit',
    description: 'Sed do eiusmod tempor incididunt ut labore et dolore.',
  },
  {
    metric: '9,012',
    label: 'Amet Consectetur',
    description: 'Ut enim ad minim veniam, quis nostrud exercitation.',
  },
  {
    metric: '3,456',
    label: 'Adipiscing Elit',
    description: 'Duis aute irure dolor in reprehenderit in voluptate.',
  },
];

export function ProjectStats() {
  return (
    <section className="bg-card/30 border-y border-border py-20 md:py-32">
      <div className="container mx-auto px-4">
        <div className="text-center mb-16">
          <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full border border-border bg-card/50 mb-6">
            <BarChart3 className="h-4 w-4 text-primary" />
            <span className="text-xl font-medium text-muted-foreground">By the Numbers</span>
          </div>
          <h2 className="text-3xl md:text-5xl font-bold mb-4 text-foreground">
            Project Statistics
          </h2>
          <p className="text-2xl text-muted-foreground max-w-3xl mx-auto">
            A snapshot of the OPBX project, by the numbers
          </p>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
          {stats.map((stat, idx) => (
            <Card
              key={idx}
              className="bg-card border-border hover:border-primary/50 transition-all duration-300 hover:-translate-y-1 hover:shadow-xl text-center"
            >
              <CardHeader>
                <CardTitle className="text-4xl md:text-5xl font-bold text-primary">
                  {stat.metric}
                </CardTitle>
              </CardHeader>
              <CardContent>
                <p className="text-xl font-medium text-foreground mb-2">{stat.label}</p>
                <p className="text-base text-muted-foreground">{stat.description}</p>
              </CardContent>
            </Card>
          ))}
        </div>
      </div>
    </section>
  );
}
