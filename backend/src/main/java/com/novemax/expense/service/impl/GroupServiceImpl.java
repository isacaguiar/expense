package com.novemax.expense.service.impl;

import com.novemax.expense.dto.GroupDTO;
import com.novemax.expense.model.Group;
import com.novemax.expense.model.User;
import com.novemax.expense.repository.GroupRepository;
import com.novemax.expense.repository.UserRepository;
import com.novemax.expense.service.GroupService;
import com.novemax.expense.util.IdGenerator;
import jakarta.transaction.Transactional;
import java.time.LocalDateTime;
import java.util.List;
import java.util.Set;
import java.util.UUID;
import java.util.stream.Collectors;
import org.springframework.stereotype.Service;

@Service
public class GroupServiceImpl implements GroupService {
  private final GroupRepository groupRepository;
  private final UserRepository userRepository;

  public GroupServiceImpl(GroupRepository groupRepository, UserRepository userRepository) {
    this.groupRepository = groupRepository;
    this.userRepository = userRepository;
  }

  @Override
  @Transactional
  public GroupDTO createGroup(String creatorEmail, GroupDTO dto) {
    User creator = userRepository.findByEmail(creatorEmail).orElseThrow();
    Group group = Group.builder()
        .id(IdGenerator.generate())
        .name(dto.getName())
        .description(dto.getDescription())
        .createDate(LocalDateTime.now())
        .deleted(false)
        .build();
    group.getUsers().add(creator);
    Group saved = groupRepository.save(group);

    GroupDTO response = new GroupDTO();
    response.setId(saved.getId());
    response.setName(saved.getName());
    response.setDescription(saved.getDescription());
    return response;
  }

  @Override
  public List<GroupDTO> getGroupsByUser(String userEmail) {
    return groupRepository.findByUsers_EmailAndDeletedFalse(userEmail).stream().map(g -> {
      GroupDTO dto = new GroupDTO();
      dto.setId(g.getId());
      dto.setName(g.getName());
      dto.setCreateDate(g.getCreateDate());
      dto.setDescription(g.getDescription());
      return dto;
    }).collect(Collectors.toList());
  }

  @Override
  public GroupDTO getGroupById(String userEmail, UUID groupId) {
    Group group = groupRepository.findByIdAndUsers_EmailAndDeletedFalse(groupId, userEmail)
        .orElseThrow(() -> new RuntimeException("Grupo não encontrado ou não pertence ao usuário"));
    GroupDTO dto = new GroupDTO();
    dto.setId(group.getId());
    dto.setName(group.getName());
    dto.setDescription(group.getDescription());
    return dto;
  }

  @Override
  @Transactional
  public GroupDTO updateGroup(String userEmail, UUID groupId, GroupDTO dto) {
    Group group = groupRepository.findByIdAndUsers_EmailAndDeletedFalse(groupId, userEmail)
        .orElseThrow(() -> new RuntimeException("Grupo não encontrado ou não pertence ao usuário"));
    group.setName(dto.getName());
    group.setDescription(dto.getDescription());
    Group updated = groupRepository.save(group);

    GroupDTO response = new GroupDTO();
    response.setId(updated.getId());
    response.setName(updated.getName());
    response.setDescription(updated.getDescription());
    return response;
  }

  @Override
  @Transactional
  public void deleteGroup(String userEmail, UUID groupId) {
    Group group = groupRepository.findByIdAndUsers_EmailAndDeletedFalse(groupId, userEmail)
        .orElseThrow(() -> new RuntimeException("Grupo não encontrado ou não pertence ao usuário"));
    group.setDeleted(true);
    groupRepository.save(group);
  }

  @Override
  @Transactional
  public void addUserToGroup(UUID groupId, String userEmail) {
    Group group = groupRepository.findByIdAndDeletedFalse(groupId)
        .orElseThrow(() -> new RuntimeException("Grupo não encontrado ou foi excluído"));
    User user = userRepository.findByEmail(userEmail).orElseThrow();
    group.getUsers().add(user);
    groupRepository.save(group);
  }

//  @Override
//  @Transactional
//  public void addUsersToGroup(UUID groupId, List<EmailDTO> userEmails) {
//    // 1. Verifica se o grupo existe e está ativo (deleted = false)
//    Group group = groupRepository.findByIdAndDeletedFalse(groupId)
//        .orElseThrow(() -> new RuntimeException("Grupo não encontrado ou excluído"));
//
//    List<String> emailStrings = userEmails.stream()
//        .map(EmailDTO::getEmail)
//        .toList();
//
//    // 2. Busca os usuários por email
//    List<User> usersToAdd = userRepository.findByEmailIn(emailStrings);
//
//    if (usersToAdd.isEmpty()) {
//      throw new RuntimeException("Nenhum usuário válido encontrado para os e-mails informados.");
//    }
//
//    // 3. Filtra apenas os usuários que ainda não estão no grupo
//    Set<UUID> existingUserIds = group.getUsers().stream()
//        .map(User::getId)
//        .collect(Collectors.toSet());
//
//    List<User> newUsers = usersToAdd.stream()
//        .filter(user -> !existingUserIds.contains(user.getId()))
//        .toList();
//
//    // 4. Adiciona os novos usuários ao grupo
//    group.getUsers().addAll(newUsers);
//
//    // 5. Salva o grupo com os novos membros
//    groupRepository.save(group);
//  }


}
