import React from 'react';
import Box from '@mui/material/Box';
import { SelectChangeEvent } from '@mui/material';

type GroupOption = {
  id: number;
  name: string;
};

interface GroupSummaryHeaderProps {
  groups: GroupOption[];
  groupId: string;
  onGroupChange: (event: SelectChangeEvent<number>) => void;
}

export default function GroupSummaryHeader(props: GroupSummaryHeaderProps) {
  return <Box component="header" data-group-id={props.groupId} />;
}
