package models

import (
	"fmt"
	"time"
)

// FlexTime is a flexible time type that can parse multiple datetime formats
type FlexTime time.Time

// UnmarshalJSON implements custom JSON unmarshaling for FlexTime
func (ft *FlexTime) UnmarshalJSON(data []byte) error {
	// Remove quotes
	str := string(data)
	if len(str) >= 2 && str[0] == '"' && str[len(str)-1] == '"' {
		str = str[1 : len(str)-1]
	}

	if str == "" || str == "null" {
		return nil
	}

	// Try multiple formats
	formats := []string{
		time.RFC3339,                    // 2006-01-02T15:04:05Z07:00
		time.RFC3339Nano,                // 2006-01-02T15:04:05.999999999Z07:00
		"2006-01-02T15:04:05",           // 2006-01-02T15:04:05
		"2006-01-02 15:04:05",           // 2006-01-02 15:04:05 (Laravel default)
		"2006-01-02 15:04:05 +0000 UTC", // Go String() format
		"2006-01-02 15:04:05 +0000 +0000",
		"2006-01-02",
	}

	for _, format := range formats {
		t, err := time.Parse(format, str)
		if err == nil {
			*ft = FlexTime(t)
			return nil
		}
	}

	return fmt.Errorf("cannot parse time %q", str)
}

// MarshalJSON implements custom JSON marshaling for FlexTime
func (ft FlexTime) MarshalJSON() ([]byte, error) {
	t := time.Time(ft)
	if t.IsZero() {
		return []byte("null"), nil
	}
	return []byte(fmt.Sprintf("%q", t.Format(time.RFC3339))), nil
}

// Time returns the underlying time.Time
func (ft FlexTime) Time() time.Time {
	return time.Time(ft)
}

// String returns the string representation
func (ft FlexTime) String() string {
	return time.Time(ft).Format(time.RFC3339)
}

// IsZero returns true if the time is zero
func (ft FlexTime) IsZero() bool {
	return time.Time(ft).IsZero()
}
