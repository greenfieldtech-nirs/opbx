import { Phone } from 'lucide-react';
import { Button } from '@/components/ui/button';

export interface WebPhoneToggleProps {
  onClick: () => void;
}

export function WebPhoneToggle({ onClick }: WebPhoneToggleProps) {
  return (
    <Button variant="outline" size="sm" onClick={onClick} className="gap-2">
      <Phone className="h-4 w-4" />
      Web Phone
    </Button>
  );
}

export default WebPhoneToggle;
