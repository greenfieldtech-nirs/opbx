import React, { useState, useEffect } from 'react';
import { Calendar } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import logger from '@/utils/logger';

interface Country {
  countryCode: string;
  name: string;
}

interface Holiday {
  date: string;
  name: string;
  localName: string;
}

interface HolidayImportButtonProps {
  onImportHolidays: (holidays: { date: string; name: string }[]) => void;
}

export const HolidayImportButton: React.FC<HolidayImportButtonProps> = ({ onImportHolidays }) => {
  const [open, setOpen] = useState(false);
  const [countries, setCountries] = useState<Country[]>([]);
  const [selectedCountry, setSelectedCountry] = useState<string>('');
  const [selectedYear, setSelectedYear] = useState<string>(new Date().getFullYear().toString());
  const [holidays, setHolidays] = useState<Holiday[]>([]);
  const [selectedHolidays, setSelectedHolidays] = useState<Set<string>>(new Set());
  const [loadingCountries, setLoadingCountries] = useState(false);
  const [loadingHolidays, setLoadingHolidays] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const currentYear = new Date().getFullYear();
  const years = Array.from({ length: 5 }, (_, i) => currentYear + i);

  useEffect(() => {
    if (open && countries.length === 0) {
      fetchCountries();
    }
  }, [open, countries.length]);

  const fetchCountries = async () => {
    setLoadingCountries(true);
    setError(null);

    try {
      const response = await fetch('https://date.nager.at/api/v3/AvailableCountries');

      if (!response.ok) {
        throw new Error('Failed to fetch countries');
      }

      const data: Country[] = await response.json();
      const sortedCountries = data.sort((a, b) => a.name.localeCompare(b.name));
      setCountries(sortedCountries);
    } catch (err) {
      setError('Failed to load countries. Please try again.');
      logger.error('Error fetching countries:', { error: err });
    } finally {
      setLoadingCountries(false);
    }
  };

  const fetchHolidays = async (countryCode: string, year: string) => {
    if (!countryCode || !year) return;

    setLoadingHolidays(true);
    setError(null);
    setHolidays([]);
    setSelectedHolidays(new Set());

    try {
      const response = await fetch(`https://date.nager.at/api/v3/publicholidays/${year}/${countryCode}`);

      if (!response.ok) {
        throw new Error('Failed to fetch holidays');
      }

      const data = await response.json();
      setHolidays(data);
    } catch (err) {
      setError('Failed to load holidays. Please try again.');
      logger.error('Error fetching holidays:', { error: err });
    } finally {
      setLoadingHolidays(false);
    }
  };

  const handleCountryChange = (countryCode: string) => {
    setSelectedCountry(countryCode);
    fetchHolidays(countryCode, selectedYear);
  };

  const handleYearChange = (year: string) => {
    setSelectedYear(year);
    if (selectedCountry) {
      fetchHolidays(selectedCountry, year);
    }
  };

  const handleImport = () => {
    const holidaysToImport = holidays
      .filter((h) => selectedHolidays.has(h.date))
      .map((h) => ({ date: h.date, name: h.name }));

    onImportHolidays(holidaysToImport);

    toast.success(`Imported ${holidaysToImport.length} holiday${holidaysToImport.length !== 1 ? 's' : ''}`);
    handleOpenChange(false);
  };

  const toggleHoliday = (date: string) => {
    setSelectedHolidays((prev) => {
      const next = new Set(prev);
      if (next.has(date)) {
        next.delete(date);
      } else {
        next.add(date);
      }
      return next;
    });
  };

  const selectAll = () => {
    setSelectedHolidays(new Set(holidays.map((h) => h.date)));
  };

  const selectNone = () => {
    setSelectedHolidays(new Set());
  };

  const handleOpenChange = (open: boolean) => {
    setOpen(open);
    if (!open) {
      setError(null);
      setSelectedCountry('');
      setSelectedYear(new Date().getFullYear().toString());
      setHolidays([]);
      setSelectedHolidays(new Set());
    }
  };

  return (
    <>
      <Button variant="outline" size="sm" onClick={() => setOpen(true)}>
        <Calendar className="mr-2 h-4 w-4" />
        Import Holidays
      </Button>

      <Dialog open={open} onOpenChange={handleOpenChange}>
        <DialogContent className="max-w-2xl max-h-[80vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>Import Holidays</DialogTitle>
            <DialogDescription>
              Select a country and year to automatically add public holidays to your schedule
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-4">
            {loadingCountries ? (
              <div className="flex items-center justify-center py-8 text-muted-foreground">
                <div className="flex items-center gap-2">
                  <div className="animate-spin rounded-full h-4 w-4 border-2 border-primary border-t-transparent" />
                  <span>Loading countries...</span>
                </div>
              </div>
            ) : error && countries.length === 0 ? (
              <div className="p-4 border border-destructive/50 bg-destructive/10 rounded-lg text-sm text-destructive">
                {error}
              </div>
            ) : (
              <>
                <div className="grid grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label>Country</Label>
                    <Select value={selectedCountry} onValueChange={handleCountryChange} disabled={loadingCountries}>
                      <SelectTrigger>
                        <SelectValue placeholder="Choose a country" />
                      </SelectTrigger>
                      <SelectContent>
                        {countries.map((country) => (
                          <SelectItem key={country.countryCode} value={country.countryCode}>
                            {country.name}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>

                  <div className="space-y-2">
                    <Label>Year</Label>
                    <Select value={selectedYear} onValueChange={handleYearChange}>
                      <SelectTrigger>
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {years.map((year) => (
                          <SelectItem key={year} value={year.toString()}>
                            {year}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>
                </div>

                {loadingHolidays && (
                  <div className="flex items-center justify-center py-8 text-muted-foreground">
                    <div className="flex items-center gap-2">
                      <div className="animate-spin rounded-full h-4 w-4 border-2 border-primary border-t-transparent" />
                      <span>Loading holidays...</span>
                    </div>
                  </div>
                )}

                {error && !loadingCountries && (
                  <div className="p-4 border border-destructive/50 bg-destructive/10 rounded-lg text-sm text-destructive">
                    {error}
                  </div>
                )}

                {!loadingHolidays && !error && holidays.length > 0 && (
                  <div className="space-y-3">
                    <div className="flex items-center justify-between">
                      <Label>Select Holidays</Label>
                      <div className="flex gap-2">
                        <Button variant="ghost" size="sm" onClick={selectAll}>
                          Select All
                        </Button>
                        <Button variant="ghost" size="sm" onClick={selectNone}>
                          Clear
                        </Button>
                      </div>
                    </div>

                    <div className="border rounded-lg p-4 space-y-2 max-h-[400px] overflow-y-auto">
                      {holidays.map((holiday) => (
                        <div key={holiday.date} className="flex items-center space-x-2">
                          <Checkbox
                            id={`holiday-${holiday.date}`}
                            checked={selectedHolidays.has(holiday.date)}
                            onCheckedChange={() => toggleHoliday(holiday.date)}
                          />
                          <Label htmlFor={`holiday-${holiday.date}`} className="font-normal flex-1 cursor-pointer">
                            <span className="font-medium">{holiday.name}</span>
                            {holiday.localName !== holiday.name && (
                              <span className="text-muted-foreground text-xs ml-2">({holiday.localName})</span>
                            )}
                            <span className="text-muted-foreground ml-2 text-xs">- {holiday.date}</span>
                          </Label>
                        </div>
                      ))}
                    </div>

                    <p className="text-sm text-muted-foreground">
                      {selectedHolidays.size} of {holidays.length} holidays selected
                    </p>
                  </div>
                )}

                {!loadingHolidays && !error && selectedCountry && holidays.length === 0 && (
                  <div className="text-center py-8 text-muted-foreground">
                    No holidays found for the selected country and year.
                  </div>
                )}
              </>
            )}
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => handleOpenChange(false)}>
              Cancel
            </Button>
            <Button
              onClick={handleImport}
              disabled={loadingHolidays || loadingCountries || !selectedCountry || selectedHolidays.size === 0}
            >
              Import {selectedHolidays.size > 0 && `(${selectedHolidays.size})`}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
};
