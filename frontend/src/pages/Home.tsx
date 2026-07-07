import { HomeHeader } from './Home/HomeHeader';
import { Hero } from './Home/Hero';
import { DograhSpotlight } from './Home/DograhSpotlight';
import { FeaturesGrid } from './Home/FeaturesGrid';
import { HowItWorks } from './Home/HowItWorks';
import { TechnologySection } from './Home/TechnologySection';
import { FAQSection } from './Home/FAQSection';
import { FinalCTA } from './Home/FinalCTA';
import { Footer } from './Home/Footer';

export default function Home() {
  return (
    <div className="min-h-screen bg-background">
      <HomeHeader />
      <Hero />
      <DograhSpotlight />
      <FeaturesGrid />
      <HowItWorks />
      <TechnologySection />
      <FAQSection />
      <FinalCTA />
      <Footer />
    </div>
  );
}
