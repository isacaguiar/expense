import React from 'react';
import Box from '@mui/material/Box';

interface GroupSummarySidebarProps {
  groupId: string;
}

export default function GroupSummarySidebar({ groupId }: GroupSummarySidebarProps) {
  return (
    <Box
      component="nav"
      data-group-id={groupId}
      sx={{
        width: 280,
        flexShrink: 0,
        borderRight: '1px solid',
        borderColor: 'divider',
        bgcolor: 'background.paper',
      }}
    />
  );
}
