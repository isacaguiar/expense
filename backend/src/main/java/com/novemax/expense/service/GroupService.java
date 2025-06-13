package com.novemax.expense.service;

import com.novemax.expense.dto.GroupDTO;
import java.util.List;
import java.util.UUID;

public interface GroupService {
  GroupDTO createGroup(String creatorEmail, GroupDTO dto);
  List<GroupDTO> getGroupsByUser(String userEmail);
  void addUserToGroup(UUID groupId, String userEmail);

  GroupDTO getGroupById(String creatorEmail, UUID id);

  GroupDTO updateGroup(String creatorEmail, UUID id, GroupDTO groupDTO);

  void deleteGroup(String creatorEmail, UUID id);

//  void addUsersToGroup(UUID groupId, List<EmailDTO> userEmails);

}
