import React, { useEffect, useState } from 'react';
import {
  Card,
  CardContent,
  CardActions,
  Typography,
  IconButton,
  Grid,
  Container,
  CircularProgress
} from '@mui/material';
import EditIcon from '@mui/icons-material/Edit';
import GroupAddIcon from '@mui/icons-material/GroupAdd';
import AttachMoneyIcon from '@mui/icons-material/AttachMoney';
import ReceiptIcon from '@mui/icons-material/Receipt';
import axios from 'axios';
import { useNavigate } from 'react-router-dom';
import { API_BASE_URL } from '../config';

type Group = {
  id: number;
  name: string;
  description: string;
  create_date: string;
};

const Dashboard: React.FC = () => {
  const [groups, setGroups] = useState<Group[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const navigate = useNavigate();

  useEffect(() => {
    const token = localStorage.getItem('accessToken');
    axios.get(`${API_BASE_URL}/api/groups`, {
      headers: { Authorization: token ? `Bearer ${token}` : '' }
    })
      .then(res => setGroups(res.data))
      .catch(() => setGroups([]))
      .finally(() => setLoading(false));
  }, []);

  if (loading) return <CircularProgress sx={{ mt: 4 }} />;

  return (
    <Container sx={{ mt: 4 }}>
      <Typography variant="h4" gutterBottom>Meus Grupos</Typography>
      <Grid container spacing={2}>
        {groups.map(group => (
          <Grid size={{ xs: 12, md: 6 }} key={group.id}>
            <Card>
              <CardContent>
                <Typography variant="h6">{group.name}</Typography>
                <Typography variant="body2">{group.description}</Typography>
              </CardContent>
              <CardActions>
                <IconButton onClick={() => navigate(`/groups/${group.id}/edit`)}>
                  <EditIcon />
                </IconButton>
                <IconButton onClick={() => navigate(`/groups/${group.id}/members`)}>
                  <GroupAddIcon />
                </IconButton>
                <IconButton onClick={() => navigate(`/groups/${group.id}/expenses`)}>
                  <ReceiptIcon />
                </IconButton>
              </CardActions>
            </Card>
          </Grid>
        ))}
      </Grid>
    </Container>
  );
};

export default Dashboard;